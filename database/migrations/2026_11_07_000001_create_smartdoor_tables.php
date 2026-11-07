<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smartdoor_meta', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('value')->nullable();
        });
        DB::table('smartdoor_meta')->insert(['key' => 'whitelist_version', 'value' => '1']);

        // Satu baris per node pintu (ESP32). Sistem dapat menampung banyak node.
        Schema::create('devices', function (Blueprint $t) {
            $t->string('id', 64)->primary();          // contoh: door-lab-01
            $t->string('name');
            $t->string('location')->default('');
            $t->string('api_key', 64)->unique();      // dikirim ESP32 di header X-Device-Key
            $t->text('config');                       // parameter perilaku pintu (JSON)
            $t->unsignedInteger('config_ver')->default(1);
            $t->text('state')->nullable();            // snapshot state terakhir (JSON)
            $t->string('fw_version', 40)->nullable();
            $t->string('ip', 64)->nullable();
            $t->unsignedBigInteger('created_at');
            $t->unsignedBigInteger('last_seen')->nullable();
        });

        // Whitelist kartu RFID terpusat
        Schema::create('cards', function (Blueprint $t) {
            $t->string('uid', 32)->primary();         // HEX huruf besar tanpa spasi
            $t->string('name');
            $t->string('role')->default('');
            $t->boolean('active')->default(true);
            $t->boolean('all_devices')->default(true);
            $t->unsignedBigInteger('created_at');
        });

        Schema::create('card_access', function (Blueprint $t) {
            $t->string('uid', 32);
            $t->string('device_id', 64);
            $t->primary(['uid', 'device_id']);
            $t->foreign('uid')->references('uid')->on('cards')->cascadeOnDelete();
            $t->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
        });

        // Log seluruh kejadian (audit trail)
        Schema::create('door_events', function (Blueprint $t) {
            $t->id();
            $t->string('device_id', 64);
            $t->unsignedBigInteger('ts');             // waktu kejadian, epoch ms (NTP ESP32)
            $t->unsignedBigInteger('received_at');
            $t->string('type', 16);                   // ACCESS | ALARM | ALARM_RESET | DOOR | SYSTEM
            $t->string('status', 24)->nullable();     // MASUK/KELUAR/DITOLAK/REMOTE/BATAL, OPEN/CLOSE, ...
            $t->string('direction', 8)->nullable();
            $t->string('uid', 32)->nullable();
            $t->string('card_name')->nullable();
            $t->string('detail', 300)->nullable();
            $t->integer('duration_ms')->nullable();
            $t->index(['device_id', 'ts']);
            $t->index('ts');
            $t->index(['type', 'ts']);
            $t->index('uid');
        });

        // Time-series semua state sensor & aktuator
        Schema::create('telemetry', function (Blueprint $t) {
            $t->id();
            $t->string('device_id', 64);
            $t->unsignedBigInteger('ts');
            $t->boolean('lock_state')->nullable();    // 1 = terkunci
            $t->boolean('relay')->nullable();
            $t->boolean('door_open')->nullable();     // reed switch
            $t->boolean('buzzer')->nullable();
            $t->boolean('led_green')->nullable();
            $t->boolean('led_red')->nullable();
            $t->boolean('alarm')->nullable();
            $t->string('alarm_type', 24)->nullable();
            $t->integer('occupancy')->nullable();
            $t->integer('fail_count')->nullable();
            $t->boolean('readers_blocked')->nullable();
            $t->boolean('reader_in_ok')->nullable();
            $t->boolean('reader_out_ok')->nullable();
            $t->integer('rssi')->nullable();
            $t->unsignedInteger('uptime_s')->nullable();
            $t->unsignedInteger('free_heap')->nullable();
            $t->integer('queue_len')->nullable();
            $t->index(['device_id', 'ts']);
        });

        // Perintah dari web ke ESP32
        Schema::create('device_commands', function (Blueprint $t) {
            $t->id();
            $t->string('device_id', 64);
            $t->string('type', 16);
            $t->string('status', 12)->default('pending'); // pending | sent | done | failed | expired
            $t->string('issued_by')->nullable();
            $t->unsignedBigInteger('created_at');
            $t->unsignedBigInteger('sent_at')->nullable();
            $t->unsignedBigInteger('acked_at')->nullable();
            $t->string('result', 200)->nullable();
            $t->index(['device_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['device_commands', 'telemetry', 'door_events', 'card_access', 'cards', 'devices', 'smartdoor_meta'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
