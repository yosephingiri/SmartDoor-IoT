// ====== Konfigurasi node — salin dari menu "Perangkat > API key" di dashboard ======
#pragma once

#define WIFI_SSID     ""
#define WIFI_PASS     ""

// URL server cloud kalian (tanpa "/" di akhir). Contoh:
//   "http://192.168.1.10:8000"              (php artisan serve --host=0.0.0.0 di laptop)
//   "http://192.168.1.10/smartdoor/public"  (XAMPP / Laragon, folder di htdocs)
//   "https://smartdoor.domainkalian.id"  (server di VPS / cloud)
#define SERVER_URL    "http://172.24.187.229/smartdoor/public"
#define DEVICE_ID     "door-lab-01"
#define DEVICE_KEY    ""

#define FW_VERSION    "1.1.0"

// ====== Pin (sesuai rangkaian breadboard yang sudah diuji per tahap) ======
// Bus SPI dipakai bersama kedua RC522 (breadboard baris 2, 4, 6)
#define PIN_SCK       18
#define PIN_MISO      19
#define PIN_MOSI      23
// RC522 sisi luar  -> akses MASUK
#define PIN_SS_IN      5
#define PIN_RST_IN    22
// RC522 sisi dalam -> akses KELUAR
#define PIN_SS_OUT    21
#define PIN_RST_OUT    4

#define PIN_REED      27   // MC-38 ke GND, INPUT_PULLUP: LOW = tertutup, HIGH = terbuka
#define PIN_RELAY     13   // lewat transistor S8050 -> IN modul relay
#define PIN_BUZZER    25   // buzzer PASIF lewat transistor S8050, dibunyikan dengan tone()
#define PIN_LED_GREEN 26
#define PIN_LED_RED   32

// Modul relay aktif LOW, tetapi dikendalikan lewat transistor NPN,
// sehingga dari sisi ESP32: GPIO HIGH = relay ON (kunci terbuka).
#define RELAY_ACTIVE_HIGH  true

// ====== Nada buzzer pasif (Hz) ======
#define BUZZER_FREQ_OK        2700   // akses diterima (2x pendek)
#define BUZZER_FREQ_DENY      1200   // akses ditolak (3x panjang)
#define BUZZER_FREQ_ALARM_HI  2700   // alarm: sirene bergantian
#define BUZZER_FREQ_ALARM_LO  1800
