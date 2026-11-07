# Smart Door Cloud — versi Laravel (PHP)

Cloud dan dashboard web untuk **Smart Door Access Control & Real-Time Logging System**, pengganti Blynk. Seluruh frontend dan backend memakai Laravel/PHP. Bisa dijalankan di laptop (`php artisan serve`), XAMPP/Laragon, atau hosting PHP biasa.

Folder ini berisi **file aplikasi** yang ditimpakan ke project Laravel baru:

| Folder | Isi |
|---|---|
| `app/Services/SmartDoor.php` | Logika inti: simpan event & telemetri, perintah remote, whitelist, status online |
| `app/Http/Controllers/` | `DeviceApiController` (API ESP32), `PanelController`, `DeviceController`, `CardController`, `AnalyticsController`, `AuthController` |
| `app/Http/Middleware/DeviceAuth.php` | Autentikasi ESP32 lewat header `X-Device-Key` |
| `app/Console/Commands/SimulateDevices.php` | `php artisan smartdoor:simulate`, simulator banyak node ESP32 |
| `database/migrations/` | Tabel `devices`, `cards`, `card_access`, `door_events`, `telemetry`, `device_commands` |
| `resources/views/smartdoor/` | Halaman login & dashboard (Blade) |
| `public/smartdoor/` | JS & CSS dashboard (tanpa build tool, tanpa npm) |
| `routes/web.php`, `routes/api.php`, `bootstrap/app.php`, `config/smartdoor.php` | Routing & konfigurasi |
| `firmware/smart_door_esp32/` | Firmware ESP32 (tanpa Blynk) |

---

## 1. Instalasi (±10 menit)

Kebutuhan: **PHP 8.2+** dan **Composer** (sudah ada kalau pakai XAMPP terbaru atau Laragon).

```bash
# 1. buat project Laravel baru
composer create-project laravel/laravel smartdoor
cd smartdoor

# 2. salin SEMUA folder dari zip ini ke dalam folder smartdoor, timpa file yang sama
#    (app, bootstrap, config, database, public, resources, routes)

# 3. buat tabel + akun admin
php artisan migrate --seed

# 4. jalankan server (0.0.0.0 supaya ESP32 di Wi-Fi yang sama bisa mengakses)
php artisan serve --host=0.0.0.0 --port=8000
```

Buka `http://localhost:8000`, lalu login dengan **admin@smartdoor.test / admin123**.

Ganti akun admin lewat `.env` sebelum `migrate --seed` (atau jalankan `php artisan db:seed` lagi setelah mengubahnya):

```env
SMARTDOOR_ADMIN_EMAIL=admin@kelompok.ac.id
SMARTDOOR_ADMIN_PASSWORD=passwordKuat
```

### Database

* **SQLite** (default Laravel, tanpa setting apa pun). Cocok untuk mencoba dan untuk demo.
* **MySQL di XAMPP**: buat database `smartdoor` di phpMyAdmin, lalu ubah `.env`:
  ```env
  DB_CONNECTION=mysql
  DB_HOST=127.0.0.1
  DB_PORT=3306
  DB_DATABASE=smartdoor
  DB_USERNAME=root
  DB_PASSWORD=
  ```
  Setelah itu jalankan `php artisan migrate --seed`.

Query analitik sudah dibuat agar jalan di SQLite, MySQL/MariaDB, dan PostgreSQL.

### Pengaturan tambahan (.env, opsional)

```env
SMARTDOOR_TZ_OFFSET_MIN=420        # WIB
SMARTDOOR_ONLINE_TIMEOUT_MS=15000  # batas dianggap offline
SMARTDOOR_COMMAND_TTL_MS=30000     # perintah remote basi dibatalkan
SMARTDOOR_RETENTION_DAYS=180       # lama simpan telemetri
```

---

## 2. Coba tanpa hardware

```bash
# isi data 14 hari ke belakang untuk 4 pintu (langsung ke database, beberapa detik)
php artisan smartdoor:simulate --devices=4 --backfill=14

# node virtual live yang memanggil API persis seperti ESP32 (terminal kedua, server harus jalan)
php artisan smartdoor:simulate --devices=4 --live --url=http://127.0.0.1:8000
```

Untuk demo scale-up ke dosen, gunakan misalnya `--devices=20`.

> `php artisan serve` hanya melayani satu request sekaligus. Itu cukup untuk 1–5 node. Untuk simulasi puluhan node, jalankan lewat Apache (XAMPP/Laragon). Di Linux/macOS bisa juga `PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=0.0.0.0`.

---

## 3. Menghubungkan ESP32

1. Di dashboard, buka **Perangkat → + Tambah perangkat**. Server menampilkan potongan `config.h` berisi `SERVER_URL`, `DEVICE_ID`, dan `DEVICE_KEY`.
2. Isi `firmware/smart_door_esp32/config.h`. `SERVER_URL` harus memakai **IP laptop** di jaringan Wi-Fi (cek dengan `ipconfig`), bukan `localhost`. Contoh: `http://192.168.1.10:8000`.
3. Arduino IDE: instal library **MFRC522** dan **ArduinoJson v7**, pilih board *ESP32 Dev Module*, lalu upload.
4. Kalau solenoid malah terbuka saat ESP32 menyala, ubah `RELAY_ACTIVE_HIGH` menjadi `false` (modul relay aktif LOW).
5. Tempelkan kartu baru di reader. UID-nya akan muncul di **Kartu RFID → Kartu tidak dikenal**; klik **Daftarkan**.

Izinkan port 8000 di Windows Firewall kalau ESP32 tidak bisa terhubung.

---

## 4. Arsitektur

```
 [RC522 luar] [RC522 dalam] [Reed MC-38]
        \_____ SPI ____|___ GPIO __/
                  ESP32 (edge)  ──Wi-Fi── HTTP + JSON ──▶  Laravel
        /             |          \                      ├─ routes/api.php  /api/v1/*  (X-Device-Key)
 [Relay+Solenoid]  [Buzzer]     [LED]                    ├─ routes/web.php  /, /panel/* (login sesi)
                                                         ├─ Database: SQLite / MySQL
  ESP32 lain (node 2..N) ───────────────────────────▶   └─ AnalyticsController
                                                                  ▲
                                         Browser pengelola ── polling /panel/live tiap 2 dtk
```

* Keputusan buka/tolak dibuat di ESP32 dengan whitelist yang disimpan di flash, jadi pintu tetap berfungsi saat internet putus. Event yang belum terkirim ditahan lalu dikirim ulang.
* Dashboard memakai polling ringan (2 detik), bukan WebSocket, supaya jalan di hosting PHP mana pun tanpa server tambahan.

### API ESP32

| Arah | Endpoint | Isi | Kapan |
|---|---|---|---|
| ESP32 → Cloud | `POST /api/v1/events` | Akses (MASUK/KELUAR/DITOLAK/REMOTE/BATAL), alarm, pintu buka/tutup + durasi, sistem | Saat kejadian |
| ESP32 → Cloud | `POST /api/v1/telemetry` | **Semua state sensor & aktuator**: kunci, relay, reed, buzzer, LED, alarm, jumlah orang, hitungan gagal, blokir, kesehatan kedua RC522, RSSI, uptime, heap | Saat berubah + tiap 10 dtk |
| Cloud → ESP32 | `GET /api/v1/commands` | UNLOCK / RESET_ALARM / SYNC / REBOOT | Polling 1 dtk |
| ESP32 → Cloud | `POST /api/v1/commands/{id}/ack` | Konfirmasi eksekusi | Setelah dijalankan |
| Cloud → ESP32 | `GET /api/v1/sync` | Whitelist kartu + parameter pintu | Saat versi berubah |

Header wajib: `X-Device-Key: <api key>`. Body `events`/`telemetry` boleh satu objek atau array.

### Pemetaan dari datastream Blynk lama

| Blynk lama | Pengganti |
|---|---|
| V0 kontrol buka/kunci | Tombol **Buka pintu** → perintah `UNLOCK` |
| V1 status kunci | `telemetry.lock_state`, `relay` |
| V2 UID, V3 status akses, V4 arah | `door_events` (uid, status, direction) + nama pemilik kartu |
| V5 reed switch | `telemetry.door_open` + event `DOOR OPEN/CLOSE` dengan durasi |
| V6 alarm | `telemetry.alarm`, `alarm_type` + event `ALARM` |
| Blynk Events | Toast di dashboard + notifikasi browser |
| SuperChart | Halaman **Analitik** + grafik di **Detail sensor** |

---

## 5. Fitur dashboard

* **Dashboard**: status tiap pintu, tombol **Buka pintu** / **Reset alarm** dengan konfirmasi dari perangkat, aktivitas terbaru, dan siapa yang sedang di dalam ruangan.
* **Detail sensor**: timeline jumlah orang beserta strip pintu terbuka, solenoid aktif, dan alarm (1 jam–7 hari), snapshot state mentah, serta riwayat perintah.
* **Riwayat Akses**: filter (pintu, tipe, status, tanggal, nama/UID), paginasi, dan ekspor CSV.
* **Analitik**: KPI, tren harian, akses per jam, heatmap hari × jam, pengguna paling aktif, kartu yang sering ditolak, jenis alarm, perbandingan per pintu, dan ketersediaan perangkat.
* **Kartu RFID**: CRUD kartu, hak akses per pintu, dan registrasi cepat dari kartu tak dikenal.
* **Perangkat**: tambah node, ubah parameter tanpa upload ulang firmware, dan kelola API key.

---

## 6. Kaitan dengan catatan dosen

| Catatan | Cara dipenuhi |
|---|---|
| 2. Scale-up banyak node | Tiap ESP32 = satu baris `devices` dengan API key sendiri; semua data ber-`device_id`; whitelist & konfigurasi terpusat. Uji: `smartdoor:simulate --devices=20`. |
| 3. Cloud & interface buatan sendiri | Laravel + database + dashboard web ini, tanpa Blynk/ThingSpeak. |
| 4. Semua data dikirim, disimpan, ada analytics | Tabel `telemetry` (seluruh state) + `door_events` (seluruh kejadian) + modul Analitik. |
| 5. Prototipe mendekati riil | Mode offline, buffer event, debounce reed switch, batas waktu solenoid, perintah kedaluwarsa, health-check RC522, sinkron NTP, autentikasi per perangkat. |
