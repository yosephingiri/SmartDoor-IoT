# Smart Door Access Control & Real-Time Logging System

Sistem kontrol akses pintu berbasis RFID dengan pencatatan real-time. Setiap pintu memakai satu node ESP32 yang mengirim kejadian dan state sensor/aktuator ke server. Server menyediakan REST API untuk perangkat, dashboard web untuk pengelola, dan modul analitik.

## Struktur project

| Lokasi | Isi |
|---|---|
| `app/Services/SmartDoor.php` | Logika inti: penyimpanan event dan telemetri, perintah remote, whitelist, status online |
| `app/Http/Controllers/` | `DeviceApiController` (API perangkat), `PanelController`, `DeviceController`, `CardController`, `AnalyticsController`, `AuthController` |
| `app/Http/Middleware/DeviceAuth.php` | Autentikasi perangkat lewat header `X-Device-Key` |
| `app/Console/Commands/SimulateDevices.php` | Simulator node ESP32 (`php artisan smartdoor:simulate`) |
| `database/migrations/` | Tabel `devices`, `cards`, `card_access`, `door_events`, `telemetry`, `device_commands` |
| `resources/views/smartdoor/` | Halaman login dan dashboard |
| `public/smartdoor/` | JavaScript dan CSS dashboard |
| `routes/`, `config/smartdoor.php` | Routing dan konfigurasi |
| `firmware/smart_door_esp32/` | Firmware ESP32 |

## Menjalankan server

Kebutuhan: PHP dan Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve --host=0.0.0.0 --port=8000
```

Dashboard dapat dibuka di `http://localhost:8000`. Akun admin bawaan: **admin@smartdoor.test / admin123**. Akun ini dapat diganti lewat `.env` sebelum menjalankan `migrate --seed`:

```env
SMARTDOOR_ADMIN_EMAIL=admin@contoh.ac.id
SMARTDOOR_ADMIN_PASSWORD=passwordKuat
```

Jika memakai Laragon atau XAMPP, letakkan project di folder web server (misalnya `C:\laragon\www\smartdoor`), lalu buka `http://<IP laptop>/smartdoor/public`.

### Database

Bawaan memakai SQLite. Untuk MySQL, buat database `smartdoor`, lalu ubah `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=smartdoor
DB_USERNAME=root
DB_PASSWORD=
```

Setelah itu jalankan `php artisan migrate --seed`.

### Pengaturan opsional (`.env`)

```env
SMARTDOOR_TZ_OFFSET_MIN=420        # zona waktu (WIB)
SMARTDOOR_ONLINE_TIMEOUT_MS=15000  # batas waktu perangkat dianggap offline
SMARTDOOR_COMMAND_TTL_MS=30000     # perintah remote yang tidak diambil dibatalkan
SMARTDOOR_RETENTION_DAYS=180       # lama penyimpanan telemetri
```

## Simulasi tanpa hardware

```bash
# data 14 hari ke belakang untuk 4 pintu
php artisan smartdoor:simulate --devices=4 --backfill=14

# node virtual yang memanggil API seperti ESP32 (server harus berjalan)
php artisan smartdoor:simulate --devices=4 --live --url=http://127.0.0.1:8000
```

`php artisan serve` hanya melayani satu request dalam satu waktu. Untuk simulasi banyak node, jalankan server lewat Apache (Laragon/XAMPP).

## Menghubungkan ESP32

1. Di dashboard, buka **Perangkat → + Tambah perangkat**. Server menampilkan konfigurasi berisi `SERVER_URL`, `DEVICE_ID`, dan `DEVICE_KEY`.
2. Salin konfigurasi tersebut ke `firmware/smart_door_esp32/config.h`, lalu isi nama dan password Wi-Fi. `SERVER_URL` memakai IP laptop di jaringan Wi-Fi (cek dengan `ipconfig`), bukan `localhost`.
3. Di Arduino IDE, instal library **MFRC522** dan **ArduinoJson**, pilih board **ESP32 Dev Module**, lalu upload `smart_door_esp32.ino`.
4. Tempelkan kartu pada reader. UID kartu muncul di **Kartu RFID → Kartu tidak dikenal**, lalu klik **Daftarkan**.

Jika ESP32 tidak dapat terhubung ke server, pastikan port web server diizinkan oleh firewall dan jaringan Wi-Fi diatur sebagai jaringan Private.

### Pin ESP32

| Komponen | Pin |
|---|---|
| RC522 (bersama): SCK / MOSI / MISO | GPIO18 / GPIO23 / GPIO19 |
| RC522 luar: SDA / RST | GPIO5 / GPIO22 |
| RC522 dalam: SDA / RST | GPIO21 / GPIO4 |
| Reed switch MC-38 | GPIO27 |
| Relay (lewat transistor S8050) | GPIO13 |
| Buzzer pasif (lewat transistor S8050) | GPIO25 |
| LED hijau / LED merah | GPIO26 / GPIO32 |

## Arsitektur

```
 [RC522 luar] [RC522 dalam] [Reed MC-38]
        \_____ SPI ____|___ GPIO __/
                  ESP32  ──Wi-Fi── HTTP + JSON ──▶  Server
        /             |          \                  ├─ /api/v1/*   API perangkat (X-Device-Key)
 [Relay+Solenoid]  [Buzzer]     [LED]                ├─ /, /panel/* dashboard (login)
                                                     ├─ Database
  ESP32 lain (node 2..N) ───────────────────────▶   └─ Modul analitik
                                                              ▲
                                     Browser pengelola ── pembaruan tiap 2 detik
```

* Keputusan buka/tolak dibuat di ESP32 berdasarkan whitelist yang tersimpan di flash, sehingga pintu tetap berfungsi saat koneksi terputus. Event yang belum terkirim disimpan sementara dan dikirim ulang.
* Dashboard memperbarui data setiap 2 detik.

### API perangkat

| Arah | Endpoint | Isi | Waktu |
|---|---|---|---|
| ESP32 → server | `POST /api/v1/events` | Akses (MASUK/KELUAR/DITOLAK/REMOTE/BATAL), alarm, pintu buka/tutup beserta durasi, kejadian sistem | Saat kejadian |
| ESP32 → server | `POST /api/v1/telemetry` | State sensor dan aktuator: kunci, relay, reed, buzzer, LED, alarm, jumlah orang, percobaan gagal, blokir reader, kondisi RC522, RSSI, uptime, heap | Saat berubah dan tiap 10 detik |
| Server → ESP32 | `GET /api/v1/commands` | UNLOCK / RESET_ALARM / SYNC / REBOOT | Tiap 1 detik |
| ESP32 → server | `POST /api/v1/commands/{id}/ack` | Konfirmasi eksekusi perintah | Setelah dijalankan |
| Server → ESP32 | `GET /api/v1/sync` | Whitelist kartu dan parameter pintu | Saat versi berubah |

Setiap request wajib menyertakan header `X-Device-Key: <api key>`. Body `events` dan `telemetry` dapat berupa satu objek atau array.

## Fitur dashboard

* **Dashboard**: status tiap pintu, tombol **Buka pintu** dan **Reset alarm**, aktivitas terbaru, serta daftar orang di dalam ruangan.
* **Detail sensor**: grafik jumlah orang, status pintu, solenoid, dan alarm (rentang 1 jam–7 hari), state terkini, serta riwayat perintah.
* **Riwayat akses**: filter berdasarkan pintu, tipe, status, tanggal, dan nama/UID, dengan ekspor CSV.
* **Analitik**: KPI, tren harian, akses per jam, heatmap hari × jam, pengguna paling aktif, kartu yang sering ditolak, jenis alarm, perbandingan antar pintu, dan ketersediaan perangkat.
* **Kartu RFID**: kelola kartu, hak akses per pintu, dan pendaftaran cepat dari kartu tidak dikenal.
* **Perangkat**: tambah node, ubah parameter pintu tanpa upload ulang firmware, dan kelola API key.
