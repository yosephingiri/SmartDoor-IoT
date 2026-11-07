<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Logika inti Smart Door Cloud: penyimpanan event & telemetri, perintah remote,
 * whitelist, status online, serta helper waktu. Dipakai oleh controller dan simulator.
 */
class SmartDoor
{
    public const EVENT_TYPES = ['ACCESS', 'ALARM', 'ALARM_RESET', 'DOOR', 'SYSTEM'];
    public const COMMAND_TYPES = ['UNLOCK', 'RESET_ALARM', 'SYNC', 'REBOOT'];

    public static function now(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public static function normUid(?string $u): string
    {
        return strtoupper(preg_replace('/[^0-9a-fA-F]/', '', (string) $u));
    }

    public static function defaults(): array
    {
        return config('smartdoor.default_device_config');
    }

    public static function tzSec(): int
    {
        return config('smartdoor.tz_offset_min') * 60;
    }

    /** Operator pembagian bilangan bulat yang sesuai dengan driver database. */
    public static function idiv(): string
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb']) ? ' DIV ' : ' / ';
    }

    // ------------------------------------------------------------------ meta / versi sinkronisasi
    public static function whitelistVersion(): int
    {
        return (int) DB::table('smartdoor_meta')->where('key', 'whitelist_version')->value('value');
    }

    public static function bumpWhitelist(): void
    {
        DB::table('smartdoor_meta')->where('key', 'whitelist_version')->update(['value' => (string) (self::whitelistVersion() + 1)]);
    }

    public static function syncVersion(object $dev): string
    {
        return self::whitelistVersion() . '.' . $dev->config_ver;
    }

    // ------------------------------------------------------------------ perangkat
    public static function isOnline(object $d): bool
    {
        return $d->last_seen && self::now() - (int) $d->last_seen < config('smartdoor.online_timeout_ms');
    }

    public static function deviceRow(object $d): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'location' => $d->location,
            'config' => array_merge(self::defaults(), json_decode($d->config ?: '{}', true) ?: []),
            'state' => $d->state ? json_decode($d->state, true) : null,
            'fwVersion' => $d->fw_version,
            'ip' => $d->ip,
            'createdAt' => (int) $d->created_at,
            'lastSeen' => $d->last_seen ? (int) $d->last_seen : null,
            'online' => self::isOnline($d),
        ];
    }

    /** Catat bahwa perangkat masih hidup. DB hanya ditulis maks. tiap 5 detik per perangkat. */
    public static function touch(object $dev, ?string $ip): void
    {
        $t = self::now();
        if (!$dev->last_seen || $t - (int) $dev->last_seen > 5000) {
            DB::table('devices')->where('id', $dev->id)->update(['last_seen' => $t, 'ip' => $ip]);
            $dev->last_seen = $t;
        }
    }

    public static function cardsForDevice(string $deviceId): array
    {
        return DB::table('cards')
            ->where('active', true)
            ->where(function ($q) use ($deviceId) {
                $q->where('all_devices', true)->orWhereExists(function ($s) use ($deviceId) {
                    $s->select(DB::raw(1))->from('card_access')
                        ->whereColumn('card_access.uid', 'cards.uid')
                        ->where('card_access.device_id', $deviceId);
                });
            })
            ->orderBy('uid')->get(['uid', 'name'])->map(fn ($r) => ['uid' => $r->uid, 'name' => $r->name])->all();
    }

    // ------------------------------------------------------------------ perintah
    public static function expireCommands(): void
    {
        DB::table('device_commands')->where('status', 'pending')
            ->where('created_at', '<', self::now() - config('smartdoor.command_ttl_ms'))
            ->update(['status' => 'expired']);
    }

    public static function takeCommands(object $dev): array
    {
        $rows = DB::table('device_commands')
            ->where('device_id', $dev->id)->where('status', 'pending')
            ->where('created_at', '>=', self::now() - config('smartdoor.command_ttl_ms'))
            ->orderBy('id')->get(['id', 'type']);
        if ($rows->isNotEmpty()) {
            DB::table('device_commands')->whereIn('id', $rows->pluck('id'))->update(['status' => 'sent', 'sent_at' => self::now()]);
        }
        return $rows->map(fn ($r) => ['id' => (int) $r->id, 'type' => $r->type])->all();
    }

    public static function deviceReply(object $dev, array $extra = []): array
    {
        return array_merge($extra, [
            'commands' => self::takeCommands($dev),
            'syncVersion' => self::syncVersion($dev),
            'serverTime' => self::now(),
        ]);
    }

    // ------------------------------------------------------------------ ingest
    private static function fixTs($ts): int
    {
        $t = self::now();
        $ts = (int) ($ts ?: 0);
        // jam ESP32 belum sinkron NTP / tidak masuk akal -> pakai waktu server
        return ($ts < 1600000000000 || $ts > $t + 60000) ? $t : $ts;
    }

    public static function storeEvent(object $dev, array $e): ?array
    {
        $type = strtoupper((string) ($e['type'] ?? ''));
        if (!in_array($type, self::EVENT_TYPES, true)) return null;

        $uid = !empty($e['uid']) ? self::normUid($e['uid']) : null;
        $detail = isset($e['detail']) ? mb_substr((string) $e['detail'], 0, 300) : null;
        // perintah remote: "cmd:<id>" diganti nama pengelola yang menekan tombol
        if ($detail && preg_match('/^cmd:(\d+)$/', $detail, $m)) {
            $detail = DB::table('device_commands')->where('id', (int) $m[1])->value('issued_by');
        }
        $row = [
            'device_id' => $dev->id,
            'ts' => self::fixTs($e['ts'] ?? null),
            'received_at' => self::now(),
            'type' => $type,
            'status' => isset($e['status']) ? strtoupper((string) $e['status']) : null,
            'direction' => !empty($e['direction']) ? strtoupper((string) $e['direction']) : null,
            'uid' => $uid,
            'card_name' => $uid ? DB::table('cards')->where('uid', $uid)->value('name') : null,
            'detail' => $detail,
            'duration_ms' => isset($e['durationMs']) ? (int) $e['durationMs'] : null,
        ];
        $row['id'] = DB::table('door_events')->insertGetId($row);
        return $row;
    }

    public static function storeTelemetry(object $dev, array $s): int
    {
        $b = fn ($k) => array_key_exists($k, $s) && $s[$k] !== null ? (bool) $s[$k] : null;
        $i = fn ($k) => array_key_exists($k, $s) && $s[$k] !== null && $s[$k] !== '' ? (int) $s[$k] : null;
        $ts = self::fixTs($s['ts'] ?? null);
        DB::table('telemetry')->insert([
            'device_id' => $dev->id, 'ts' => $ts,
            'lock_state' => $b('locked'), 'relay' => $b('relay'), 'door_open' => $b('doorOpen'),
            'buzzer' => $b('buzzer'), 'led_green' => $b('ledGreen'), 'led_red' => $b('ledRed'),
            'alarm' => $b('alarm'), 'alarm_type' => $s['alarmType'] ?? null,
            'occupancy' => $i('occupancy'), 'fail_count' => $i('failCount'), 'readers_blocked' => $b('readersBlocked'),
            'reader_in_ok' => $b('readerInOk'), 'reader_out_ok' => $b('readerOutOk'),
            'rssi' => $i('rssi'), 'uptime_s' => $i('uptime'), 'free_heap' => $i('freeHeap'), 'queue_len' => $i('queueLen'),
        ]);
        return $ts;
    }

    /** Hapus telemetri lama. Dipanggil sesekali (tanpa perlu cron). */
    public static function maybePrune(): void
    {
        if (random_int(1, 500) !== 1) return;
        $cut = self::now() - config('smartdoor.retention_days') * 86400000;
        DB::table('telemetry')->where('ts', '<', $cut)->delete();
        DB::table('device_commands')->where('created_at', '<', $cut)->delete();
    }

    public static function eventOut(object|array $e): array
    {
        $e = (array) $e;
        foreach (['id', 'ts', 'received_at', 'duration_ms'] as $k) {
            if (isset($e[$k])) $e[$k] = (int) $e[$k];
        }
        return $e;
    }
}
