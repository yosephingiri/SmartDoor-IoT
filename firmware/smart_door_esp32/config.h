// ====== Konfigurasi node — salin dari menu "Perangkat > API key" di dashboard ======
#pragma once

#define WIFI_SSID     "NamaWiFi"
#define WIFI_PASS     "PasswordWiFi"

// URL server cloud kalian (tanpa "/" di akhir). Contoh:
//   "http://192.168.1.10:8000"              (php artisan serve --host=0.0.0.0 di laptop)
//   "http://192.168.1.10/smartdoor/public"  (XAMPP / Laragon, folder di htdocs)
//   "https://smartdoor.domainkalian.id"  (server di VPS / cloud)
#define SERVER_URL    "http://192.168.1.10:8000"
#define DEVICE_ID     "door-lab-01"
#define DEVICE_KEY    "ganti-dengan-api-key-dari-dashboard"

#define FW_VERSION    "1.0.0"

// ====== Pin (sesuai skematik Bab 4.4) ======
#define PIN_SCK       18
#define PIN_MISO      19
#define PIN_MOSI      23
#define PIN_RST       22   // RST dipakai bersama kedua RC522
#define PIN_SS_IN     5    // RC522 sisi luar  -> akses MASUK
#define PIN_SS_OUT    21   // RC522 sisi dalam -> akses KELUAR
#define PIN_REED      27   // MC-38, INPUT_PULLUP: LOW = tertutup, HIGH = terbuka
#define PIN_RELAY     13   // relay -> solenoid 12V
#define PIN_BUZZER    25   // via transistor S8050
#define PIN_LED_GREEN 26   // isi -1 jika tidak memakai LED
#define PIN_LED_RED   33   // isi -1 jika tidak memakai LED

// Banyak modul relay 1-channel aktif LOW (IN=LOW -> relay ON). Sesuaikan dengan modul kalian.
#define RELAY_ACTIVE_HIGH  true
