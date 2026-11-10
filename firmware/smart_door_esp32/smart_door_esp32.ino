/*
 * Smart Door Access Control & Real-Time Logging System — firmware node ESP32.
 *
 * Library (Library Manager):
 *   - MFRC522 by GithubCommunity (miguelbalboa)
 *   - ArduinoJson by Benoit Blanchon (v7.x)
 * Bawaan board ESP32: WiFi, HTTPClient, WiFiClientSecure, SPI, Preferences
 *
 * Arsitektur:
 *   Core 1 (loop)      : RFID, reed switch, relay/solenoid, buzzer, LED, logika alarm  -> tidak pernah menunggu jaringan
 *   Core 0 (netTask)   : kirim event & telemetri, polling perintah, sinkron whitelist/konfigurasi
 *   Whitelist + konfigurasi disimpan di NVS, sehingga pintu tetap bisa dibuka dengan kartu walau internet putus.
 *   Event yang gagal terkirim ditahan di buffer dan dikirim ulang saat koneksi kembali.
 */
#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Preferences.h>
#include <ArduinoJson.h>
#include <time.h>
#include <sys/time.h>
#include "config.h"

// ------------------------------------------------------------------ tipe data bersama
struct Config {
  uint32_t unlockMs = 5000;
  uint32_t leftOpenAlarmS = 30;
  uint8_t  maxFail = 3;
  uint32_t blockS = 30;
  uint32_t telemetryIntervalS = 10;
};

struct State {
  bool locked = true, relay = false, doorOpen = false, buzzer = false, ledGreen = false, ledRed = false;
  bool alarm = false; char alarmType[16] = "";
  int  occupancy = 0; uint8_t failCount = 0; bool readersBlocked = false;
  bool readerInOk = true, readerOutOk = true;
};

struct EventMsg {
  uint64_t ts; char type[12]; char status[16]; char dir[8]; char uid[16]; char detail[40]; int32_t durationMs;
};
struct CmdMsg { int32_t id; char type[16]; };
struct AckMsg { int32_t id; bool ok; char detail[40]; };

// ------------------------------------------------------------------ global
MFRC522 rfidIn(PIN_SS_IN, PIN_RST_IN);
MFRC522 rfidOut(PIN_SS_OUT, PIN_RST_OUT);
Preferences prefs;

SemaphoreHandle_t mux;           // melindungi state, cfg, whitelist
QueueHandle_t eventQ, cmdQ, ackQ;
Config cfg;
State st;
String whitelist = ",";          // format ",UID1,UID2,"
volatile bool stateDirty = true;
volatile bool forceSync = true;

// ------------------------------------------------------------------ utilitas
uint64_t nowMs() {
  struct timeval tv; gettimeofday(&tv, nullptr);
  if (tv.tv_sec < 1600000000) return 0;   // NTP belum sinkron -> server memakai waktu terima
  return (uint64_t)tv.tv_sec * 1000ULL + tv.tv_usec / 1000;
}
void lock()   { xSemaphoreTake(mux, portMAX_DELAY); }
void unlock_() { xSemaphoreGive(mux); }
void markDirty() { stateDirty = true; }

void pushEvent(const char* type, const char* status, const char* dir = "", const char* uid = "", const char* detail = "", int32_t durationMs = -1) {
  EventMsg e = {};
  e.ts = nowMs();
  strlcpy(e.type, type, sizeof e.type); strlcpy(e.status, status, sizeof e.status);
  strlcpy(e.dir, dir, sizeof e.dir); strlcpy(e.uid, uid, sizeof e.uid);
  strlcpy(e.detail, detail, sizeof e.detail); e.durationMs = durationMs;
  if (xQueueSend(eventQ, &e, 0) != pdTRUE) Serial.println("[EVT] antrean penuh, event dibuang");
  Serial.printf("[EVT] %s %s %s %s %s\n", type, status, dir, uid, detail);
}

// ------------------------------------------------------------------ aktuator
void setRelay(bool on) {
  digitalWrite(PIN_RELAY, (on == RELAY_ACTIVE_HIGH) ? HIGH : LOW);
  lock(); st.relay = on; st.locked = !on; unlock_(); markDirty();
}
void setLed(int pin, bool on) { if (pin >= 0) digitalWrite(pin, on ? HIGH : LOW); }

// Buzzer PASIF non-blocking: pola ON/OFF (ms) dengan nada tertentu. Saat alarm aktif, berbunyi sirene terus.
const uint16_t BEEP_OK[]   = {120, 100, 120};           // 2x singkat: akses diterima
const uint16_t BEEP_DENY[] = {500, 200, 500, 200, 500}; // 3x panjang: akses ditolak
const uint16_t* beepPat = nullptr; uint8_t beepLen = 0, beepIdx = 0; unsigned long beepT = 0; uint16_t beepFreq = 0;

// Mengubah nada hanya jika berbeda, supaya tone() tidak dipanggil ulang setiap putaran loop.
void buzzerSet(uint16_t freq) {
  static uint16_t current = 0;
  if (PIN_BUZZER < 0 || freq == current) return;
  current = freq;
  if (freq) tone(PIN_BUZZER, freq);
  else { noTone(PIN_BUZZER); digitalWrite(PIN_BUZZER, LOW); }
}

void beep(const uint16_t* p, uint8_t n, uint16_t freq) {
  if (PIN_BUZZER < 0) return;
  beepPat = p; beepLen = n; beepIdx = 0; beepT = millis(); beepFreq = freq;
  buzzerSet(freq);
  lock(); st.buzzer = true; unlock_(); markDirty();
}
void serviceBuzzer(bool alarmOn) {
  if (PIN_BUZZER < 0) return;
  static bool lastActive = false;
  bool active;
  if (alarmOn) {
    buzzerSet((millis() / 300) % 2 ? BUZZER_FREQ_ALARM_HI : BUZZER_FREQ_ALARM_LO);
    active = true;
  } else if (beepPat) {
    if (millis() - beepT >= beepPat[beepIdx]) {
      beepIdx++; beepT = millis();
      if (beepIdx >= beepLen) beepPat = nullptr;
    }
    buzzerSet(beepPat && beepIdx % 2 == 0 ? beepFreq : 0);
    active = beepPat != nullptr;
  } else { buzzerSet(0); active = false; }
  if (active != lastActive) { lastActive = active; lock(); st.buzzer = active; unlock_(); markDirty(); }
}

// ------------------------------------------------------------------ logika pintu
unsigned long grantedUntil = 0;       // jendela relay ON (maks. unlockMs)
bool authorizedPending = false;       // menunggu pintu dibuka setelah akses sah
char pendingDir[8] = "", pendingUid[16] = "";
unsigned long doorOpenedAt = 0, blockedUntil = 0, ledRedUntil = 0;
bool leftOpenAlarmed = false;
String lastUid = ""; unsigned long lastUidAt = 0; MFRC522* lastReader = nullptr;

void raiseAlarm(const char* type, const char* detail) {
  lock(); bool already = st.alarm; st.alarm = true; strlcpy(st.alarmType, type, sizeof st.alarmType); unlock_();
  markDirty();
  if (!already) pushEvent("ALARM", type, "", "", detail);
}
void clearAlarm(const char* source, const char* detail) {
  lock(); bool was = st.alarm || st.readersBlocked;
  st.alarm = false; st.alarmType[0] = 0; st.readersBlocked = false; st.failCount = 0; unlock_();
  blockedUntil = 0; markDirty();
  if (was) pushEvent("ALARM_RESET", source, "", "", detail);
}

void grantAccess(const char* dir, const char* uid) {
  authorizedPending = true;
  strlcpy(pendingDir, dir, sizeof pendingDir); strlcpy(pendingUid, uid, sizeof pendingUid);
  uint32_t ms = cfg.unlockMs > 9000 ? 9000 : cfg.unlockMs;       // solenoid tidak boleh > 10 detik
  grantedUntil = millis() + ms;
  setRelay(true);
  setLed(PIN_LED_GREEN, true); lock(); st.ledGreen = true; unlock_();
  beep(BEEP_OK, 3, BUZZER_FREQ_OK);
}

void endUnlockWindow() {
  setRelay(false);
  setLed(PIN_LED_GREEN, false); lock(); st.ledGreen = false; unlock_(); markDirty();
}

String readUid(MFRC522& r) {
  String s;
  for (byte i = 0; i < r.uid.size; i++) { if (r.uid.uidByte[i] < 0x10) s += '0'; s += String(r.uid.uidByte[i], HEX); }
  s.toUpperCase();
  return s;
}

void handleCard(MFRC522& reader, const char* dir) {
  if (!reader.PICC_IsNewCardPresent() || !reader.PICC_ReadCardSerial()) return;
  String uid = readUid(reader);
  reader.PICC_HaltA(); reader.PCD_StopCrypto1();
  if (uid == lastUid && &reader == lastReader && millis() - lastUidAt < 2000) return;   // debounce kartu sama
  lastUid = uid; lastUidAt = millis(); lastReader = &reader;

  lock(); bool blocked = st.readersBlocked; bool ok = whitelist.indexOf("," + uid + ",") >= 0; unlock_();
  if (blocked) return;

  if (ok) {
    lock(); st.failCount = 0; unlock_();
    pushEvent("ACCESS", dir, dir, uid.c_str());
    grantAccess(dir, uid.c_str());
  } else {
    lock(); st.failCount++; uint8_t f = st.failCount; unlock_(); markDirty();
    pushEvent("ACCESS", "DITOLAK", dir, uid.c_str());
    beep(BEEP_DENY, 5, BUZZER_FREQ_DENY);
    setLed(PIN_LED_RED, true); ledRedUntil = millis() + 2000; lock(); st.ledRed = true; unlock_();
    if (f >= cfg.maxFail) {
      blockedUntil = millis() + cfg.blockS * 1000UL;
      lock(); st.readersBlocked = true; unlock_();
      char d[40]; snprintf(d, sizeof d, "%ux kartu ditolak", f);
      raiseAlarm("BRUTE_FORCE", d);
    }
  }
}

void serviceDoor() {
  static bool stable = false, lastRaw = false; static unsigned long changedAt = 0;
  if (PIN_REED >= 0) {
    bool raw = digitalRead(PIN_REED) == HIGH;
    if (raw != lastRaw) { lastRaw = raw; changedAt = millis(); }
    if (millis() - changedAt > 50 && raw != stable) {
      stable = raw;
      lock(); st.doorOpen = stable; unlock_(); markDirty();
      if (stable) {
        doorOpenedAt = millis(); leftOpenAlarmed = false;
        pushEvent("DOOR", "OPEN");
        if (authorizedPending) {
          authorizedPending = false;
          endUnlockWindow();
          lock();
          if (!strcmp(pendingDir, "MASUK")) st.occupancy++;
          else if (!strcmp(pendingDir, "KELUAR") && st.occupancy > 0) st.occupancy--;
          int occ = st.occupancy; unlock_();
          prefs.putInt("occ", occ);
        } else {
          raiseAlarm("FORCED_OPEN", "pintu terbuka tanpa akses sah");
        }
      } else {
        pushEvent("DOOR", "CLOSE", "", "", "", (int32_t)(millis() - doorOpenedAt));
        lock(); bool leftAlarm = st.alarm && !strcmp(st.alarmType, "DOOR_LEFT_OPEN"); unlock_();
        if (leftAlarm) clearAlarm("AUTO", "pintu ditutup");
      }
    }
  }
  // akses sah tapi pintu tidak dibuka dalam batas waktu -> batal
  if (authorizedPending && (long)(millis() - grantedUntil) >= 0) {
    authorizedPending = false;
    endUnlockWindow();
    if (pendingUid[0]) pushEvent("ACCESS", "BATAL", pendingDir, pendingUid, "pintu tidak dibuka");
  }
  // pintu lupa ditutup
  if (PIN_REED >= 0 && stable && !leftOpenAlarmed && millis() - doorOpenedAt > cfg.leftOpenAlarmS * 1000UL) {
    leftOpenAlarmed = true;
    lock(); bool other = st.alarm; unlock_();
    if (!other) { char d[40]; snprintf(d, sizeof d, "pintu terbuka > %lu dtk", (unsigned long)cfg.leftOpenAlarmS); raiseAlarm("DOOR_LEFT_OPEN", d); }
  }
  // blokir reader selesai
  if (blockedUntil && (long)(millis() - blockedUntil) >= 0) {
    blockedUntil = 0;
    lock(); bool bf = !strcmp(st.alarmType, "BRUTE_FORCE"); unlock_();
    if (bf) clearAlarm("AUTO", "blokir reader selesai");
    else { lock(); st.readersBlocked = false; st.failCount = 0; unlock_(); markDirty(); }
  }
  if (ledRedUntil && (long)(millis() - ledRedUntil) >= 0) { ledRedUntil = 0; }
  lock(); bool redOn = st.alarm || st.readersBlocked || ledRedUntil; bool changed = st.ledRed != redOn; st.ledRed = redOn; unlock_();
  setLed(PIN_LED_RED, redOn); if (changed) markDirty();
}

void checkReaders() {
  static unsigned long t = 0;
  if (millis() - t < 15000) return;
  t = millis();
  byte vIn = rfidIn.PCD_ReadRegister(MFRC522::VersionReg);
  byte vOut = rfidOut.PCD_ReadRegister(MFRC522::VersionReg);
  bool okIn = vIn != 0x00 && vIn != 0xFF, okOut = vOut != 0x00 && vOut != 0xFF;
  if (!okIn)  { rfidIn.PCD_Init();  rfidIn.PCD_SetAntennaGain(MFRC522::RxGain_max); }
  if (!okOut) { rfidOut.PCD_Init(); rfidOut.PCD_SetAntennaGain(MFRC522::RxGain_max); }
  lock(); bool ch = st.readerInOk != okIn || st.readerOutOk != okOut; st.readerInOk = okIn; st.readerOutOk = okOut; unlock_();
  if (ch) { markDirty(); pushEvent("SYSTEM", "READER", "", "", okIn && okOut ? "reader normal" : "reader RC522 tidak merespons"); }
}

void handleCommands() {
  CmdMsg c;
  while (xQueueReceive(cmdQ, &c, 0) == pdTRUE) {
    AckMsg a = {}; a.id = c.id; a.ok = true;
    char tag[16]; snprintf(tag, sizeof tag, "cmd:%ld", (long)c.id);   // server mengganti dengan nama pengelola
    if (!strcmp(c.type, "UNLOCK")) {
      pushEvent("ACCESS", "REMOTE", "", "", tag);
      grantAccess("", "");
      strlcpy(a.detail, "kunci dibuka", sizeof a.detail);
    } else if (!strcmp(c.type, "RESET_ALARM")) {
      clearAlarm("REMOTE", tag);
      strlcpy(a.detail, "alarm dihentikan", sizeof a.detail);
    } else if (!strcmp(c.type, "SYNC")) {
      forceSync = true; strlcpy(a.detail, "sinkronisasi dijadwalkan", sizeof a.detail);
    } else if (!strcmp(c.type, "REBOOT")) {
      strlcpy(a.detail, "restart", sizeof a.detail);
      xQueueSend(ackQ, &a, 0); delay(1500); ESP.restart();
    } else { a.ok = false; strlcpy(a.detail, "perintah tidak dikenal", sizeof a.detail); }
    xQueueSend(ackQ, &a, 0);
  }
}

// ================================================================== JARINGAN (core 0)
String syncVersion = "";
EventMsg pending[40]; int pendingN = 0;

bool httpReq(const char* method, const String& path, const String& body, String& out) {
  static WiFiClient plain;
  static WiFiClientSecure secure;
  HTTPClient http;
  String url = String(SERVER_URL) + path;
  bool ok;
  if (url.startsWith("https")) { secure.setInsecure(); ok = http.begin(secure, url); }  // prototipe: tanpa verifikasi sertifikat
  else ok = http.begin(plain, url);
  if (!ok) return false;
  http.setReuse(true);
  http.setTimeout(4000);
  http.addHeader("X-Device-Key", DEVICE_KEY);
  http.addHeader("Content-Type", "application/json");
  int code = !strcmp(method, "GET") ? http.GET() : http.POST(body);
  if (code > 0) out = http.getString();
  http.end();
  if (code != 200) Serial.printf("[NET] %s %s -> %d\n", method, path.c_str(), code);
  return code == 200;
}

void handleServerReply(const String& resp) {
  JsonDocument doc;
  if (deserializeJson(doc, resp)) return;
  for (JsonObject c : doc["commands"].as<JsonArray>()) {
    CmdMsg m = {}; m.id = c["id"] | 0; strlcpy(m.type, c["type"] | "", sizeof m.type);
    xQueueSend(cmdQ, &m, 0);
  }
  const char* sv = doc["syncVersion"] | "";
  if (sv[0] && syncVersion != sv) forceSync = true;
}

void doSync() {
  String resp;
  if (!httpReq("GET", "/api/v1/sync", "", resp)) return;
  JsonDocument doc;
  if (deserializeJson(doc, resp)) return;
  String wl = ",";
  for (JsonObject c : doc["cards"].as<JsonArray>()) { wl += (const char*)(c["uid"] | ""); wl += ","; }
  Config n;
  n.unlockMs = doc["config"]["unlockMs"] | 5000;
  n.leftOpenAlarmS = doc["config"]["leftOpenAlarmS"] | 30;
  n.maxFail = doc["config"]["maxFail"] | 3;
  n.blockS = doc["config"]["blockS"] | 30;
  n.telemetryIntervalS = doc["config"]["telemetryIntervalS"] | 10;
  lock(); whitelist = wl; cfg = n; unlock_();
  prefs.putString("wl", wl);
  prefs.putBytes("cfg", &n, sizeof n);
  syncVersion = (const char*)(doc["syncVersion"] | "");
  forceSync = false;
  Serial.printf("[SYNC] versi %s, %d byte whitelist\n", syncVersion.c_str(), wl.length());
}

void sendEvents() {
  EventMsg e;
  while (pendingN < 40 && xQueueReceive(eventQ, &e, 0) == pdTRUE) pending[pendingN++] = e;
  if (!pendingN) return;
  JsonDocument doc; JsonArray arr = doc.to<JsonArray>();
  for (int i = 0; i < pendingN; i++) {
    JsonObject o = arr.add<JsonObject>();
    if (pending[i].ts) o["ts"] = pending[i].ts;
    o["type"] = pending[i].type; o["status"] = pending[i].status;
    if (pending[i].dir[0]) o["direction"] = pending[i].dir;
    if (pending[i].uid[0]) o["uid"] = pending[i].uid;
    if (pending[i].detail[0]) o["detail"] = pending[i].detail;
    if (pending[i].durationMs >= 0) o["durationMs"] = pending[i].durationMs;
  }
  String body, resp; serializeJson(doc, body);
  if (httpReq("POST", "/api/v1/events", body, resp)) { pendingN = 0; handleServerReply(resp); }
}

void sendTelemetry() {
  State s; lock(); s = st; unlock_();
  stateDirty = false;
  JsonDocument doc;
  uint64_t t = nowMs(); if (t) doc["ts"] = t;
  doc["locked"] = s.locked; doc["relay"] = s.relay; doc["doorOpen"] = s.doorOpen; doc["buzzer"] = s.buzzer;
  doc["ledGreen"] = s.ledGreen; doc["ledRed"] = s.ledRed;
  doc["alarm"] = s.alarm; if (s.alarmType[0]) doc["alarmType"] = s.alarmType; else doc["alarmType"] = nullptr;
  doc["occupancy"] = s.occupancy; doc["failCount"] = s.failCount; doc["readersBlocked"] = s.readersBlocked;
  doc["readerInOk"] = s.readerInOk; doc["readerOutOk"] = s.readerOutOk;
  doc["rssi"] = WiFi.RSSI(); doc["uptime"] = millis() / 1000; doc["freeHeap"] = ESP.getFreeHeap();
  doc["queueLen"] = pendingN + (int)uxQueueMessagesWaiting(eventQ); doc["fw"] = FW_VERSION;
  String body, resp; serializeJson(doc, body);
  if (httpReq("POST", "/api/v1/telemetry", body, resp)) handleServerReply(resp);
  else stateDirty = true;
}

void sendAcks() {
  AckMsg a;
  while (xQueueReceive(ackQ, &a, 0) == pdTRUE) {
    JsonDocument doc; doc["ok"] = a.ok; doc["detail"] = a.detail;
    String body, resp; serializeJson(doc, body);
    httpReq("POST", "/api/v1/commands/" + String(a.id) + "/ack", body, resp);
  }
}

void netTask(void*) {
  unsigned long lastTel = 0, lastPoll = 0, lastTry = 0;
  bool announced = false;
  for (;;) {
    if (WiFi.status() != WL_CONNECTED) {
      if (millis() - lastTry > 10000) { lastTry = millis(); Serial.println("[WIFI] menyambung ulang…"); WiFi.reconnect(); }
      vTaskDelay(pdMS_TO_TICKS(200)); continue;
    }
    if (!announced) { announced = true; pushEvent("SYSTEM", "ONLINE", "", "", WiFi.localIP().toString().c_str()); }
    if (forceSync) doSync();
    sendEvents();
    if (stateDirty || millis() - lastTel >= cfg.telemetryIntervalS * 1000UL) { lastTel = millis(); sendTelemetry(); lastPoll = millis(); }
    else if (millis() - lastPoll >= 1000) {
      lastPoll = millis();
      String resp; if (httpReq("GET", "/api/v1/commands", "", resp)) handleServerReply(resp);
    }
    sendAcks();
    vTaskDelay(pdMS_TO_TICKS(50));
  }
}

// ================================================================== SETUP / LOOP (core 1)
void setup() {
  Serial.begin(115200);
  pinMode(PIN_RELAY, OUTPUT); digitalWrite(PIN_RELAY, RELAY_ACTIVE_HIGH ? LOW : HIGH);  // kunci saat boot
  if (PIN_BUZZER >= 0) { pinMode(PIN_BUZZER, OUTPUT); digitalWrite(PIN_BUZZER, LOW); }
  if (PIN_REED >= 0) pinMode(PIN_REED, INPUT_PULLUP);
  if (PIN_LED_GREEN >= 0) pinMode(PIN_LED_GREEN, OUTPUT);
  if (PIN_LED_RED >= 0) pinMode(PIN_LED_RED, OUTPUT);

  mux = xSemaphoreCreateMutex();
  eventQ = xQueueCreate(40, sizeof(EventMsg));
  cmdQ = xQueueCreate(8, sizeof(CmdMsg));
  ackQ = xQueueCreate(8, sizeof(AckMsg));

  // muat whitelist, konfigurasi & jumlah orang dari NVS (mode offline)
  prefs.begin("smartdoor", false);
  whitelist = prefs.getString("wl", ",");
  if (prefs.getBytesLength("cfg") == sizeof(Config)) prefs.getBytes("cfg", &cfg, sizeof cfg);
  st.occupancy = prefs.getInt("occ", 0);
  st.doorOpen = PIN_REED >= 0 && digitalRead(PIN_REED) == HIGH;

  SPI.begin(PIN_SCK, PIN_MISO, PIN_MOSI);
  rfidIn.PCD_Init(); rfidOut.PCD_Init();
  rfidIn.PCD_SetAntennaGain(MFRC522::RxGain_max);    // jangkauan baca maksimal
  rfidOut.PCD_SetAntennaGain(MFRC522::RxGain_max);
  Serial.printf("RC522 luar v%02X, dalam v%02X\n", rfidIn.PCD_ReadRegister(MFRC522::VersionReg), rfidOut.PCD_ReadRegister(MFRC522::VersionReg));

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  configTime(0, 0, "pool.ntp.org", "time.google.com");   // simpan UTC; dashboard menampilkan WIB

  pushEvent("SYSTEM", "BOOT", "", "", FW_VERSION);
  xTaskCreatePinnedToCore(netTask, "net", 12288, nullptr, 1, nullptr, 0);
  Serial.printf("Smart Door %s siap. Whitelist offline: %d byte\n", DEVICE_ID, whitelist.length());
}

void loop() {
  lock(); bool alarmOn = st.alarm; unlock_();
  handleCard(rfidIn, "MASUK");     // reader sisi luar
  handleCard(rfidOut, "KELUAR");   // reader sisi dalam
  serviceDoor();
  serviceBuzzer(alarmOn);
  handleCommands();
  checkReaders();
  delay(5);
}
