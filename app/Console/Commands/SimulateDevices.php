<?php

namespace App\Console\Commands;

use App\Services\SmartDoor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Simulator banyak node ESP32 — untuk demo scale-up dan menguji dashboard tanpa hardware.
 *
 *   php artisan smartdoor:simulate --devices=4 --backfill=14          isi data historis 14 hari
 *   php artisan smartdoor:simulate --devices=20 --live --url=http://127.0.0.1:8000
 *
 * Mode --live memanggil API /api/v1/* lewat HTTP persis seperti firmware ESP32.
 */
class SimulateDevices extends Command
{
    protected $signature = 'smartdoor:simulate
        {--devices=3 : jumlah node virtual}
        {--backfill=0 : isi data historis N hari ke belakang}
        {--live : jalankan node virtual secara live lewat HTTP}
        {--url= : URL server untuk mode live (default APP_URL)}
        {--speed=1 : pengali kecepatan aktivitas live}';

    protected $description = 'Simulasikan banyak node pintu ESP32 (data historis dan/atau live)';

    private const ROOMS = ['Lab IoT', 'Lab Jaringan', 'Ruang Dosen', 'Ruang Server', 'Lab Multimedia', 'Perpustakaan', 'Ruang Rapat', 'Gudang Alat', 'Lab Robotika', 'Sekretariat'];
    private const PEOPLE = ['Lila Sianipar', 'Budi Santoso', 'Siti Rahma', 'Andi Pratama', 'Dewi Lestari', 'Rizky Hidayat', 'Nur Aisyah', 'Fajar Nugroho', 'Maya Putri', 'Yoga Saputra',
        'Intan Permata', 'Hendra Wijaya', 'Rina Marlina', 'Agus Salim', 'Putri Ayu', 'Dimas Aditya', 'Sari Indah', 'Bayu Kurniawan', 'Wulan Sari', 'Eko Prasetyo'];

    public function handle(): int
    {
        $n = max(1, (int) $this->option('devices'));
        [$devices, $cards] = $this->setup($n);
        $this->info("{$n} node virtual, " . count($cards) . ' kartu terdaftar.');

        if (($days = (int) $this->option('backfill')) > 0) {
            $this->info("Mengisi data historis {$days} hari…");
            foreach ($devices as $i => $d) $this->backfill($d, $this->usersFor($cards, $i, $n), $days);
        }
        if ($this->option('live')) $this->live($devices, $cards);
        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ persiapan
    private function setup(int $n): array
    {
        $devices = [];
        for ($i = 1; $i <= $n; $i++) {
            $id = sprintf('door-sim-%02d', $i);
            if (!DB::table('devices')->where('id', $id)->exists()) {
                DB::table('devices')->insert([
                    'id' => $id,
                    'name' => 'Pintu ' . self::ROOMS[($i - 1) % count(self::ROOMS)],
                    'location' => 'Gedung ' . chr(65 + intdiv($i - 1, 4)) . ' · Lt. ' . ((($i - 1) % 3) + 1),
                    'api_key' => Str::random(32),
                    'config' => json_encode(SmartDoor::defaults()),
                    'created_at' => SmartDoor::now(),
                ]);
            }
            $devices[] = DB::table('devices')->where('id', $id)->first();
        }
        if (DB::table('cards')->count() < 12) {
            foreach (self::PEOPLE as $name) {
                DB::table('cards')->insertOrIgnore([
                    'uid' => $this->hexUid(), 'name' => $name,
                    'role' => $this->pick(['Mahasiswa', 'Mahasiswa', 'Asisten Lab', 'Dosen', 'Staf']),
                    'active' => true, 'all_devices' => true, 'created_at' => SmartDoor::now(),
                ]);
            }
            SmartDoor::bumpWhitelist();
        }
        return [$devices, DB::table('cards')->where('active', true)->pluck('name', 'uid')->all()];
    }

    /** Tiap orang punya ruangan "rumah" agar tidak tercatat di dua ruangan sekaligus. */
    private function usersFor(array $cards, int $idx, int $total): array
    {
        return array_values(array_filter(array_keys($cards), fn ($u) => hexdec(substr($u, -2)) % $total === $idx));
    }

    private function hexUid(): string { return strtoupper(bin2hex(random_bytes(4))); }
    private function pick(array $a) { return $a[array_rand($a)]; }
    private function rnd(float $a, float $b): float { return $a + mt_rand() / mt_getrandmax() * ($b - $a); }
    private function gauss(float $m, float $s): float
    {
        return $m + $s * sqrt(-2 * log(max(1e-9, mt_rand() / mt_getrandmax()))) * cos(2 * M_PI * mt_rand() / mt_getrandmax());
    }

    private function base(array $o = []): array
    {
        return array_merge([
            'locked' => true, 'relay' => false, 'doorOpen' => false, 'buzzer' => false, 'ledGreen' => false, 'ledRed' => false,
            'alarm' => false, 'alarmType' => null, 'occupancy' => 0, 'failCount' => 0, 'readersBlocked' => false,
            'readerInOk' => true, 'readerOutOk' => true, 'rssi' => mt_rand(-72, -48), 'uptime' => 0,
            'freeHeap' => mt_rand(180000, 210000), 'queueLen' => 0, 'fw' => '1.0.0-sim',
        ], $o);
    }

    // ------------------------------------------------------------------ data historis (langsung ke database)
    private function backfill(object $d, array $users, int $days): void
    {
        $names = DB::table('cards')->pluck('name', 'uid')->all();
        $intruders = [$this->hexUid(), $this->hexUid()];
        $ev = []; $tel = [];
        $tz = SmartDoor::tzSec() * 1000;
        $now = SmartDoor::now();
        $start = $now - $days * 86400000;
        $occ = 0;
        $E = function (int $ts, string $type, string $status, array $x = []) use (&$ev, $d, $names) {
            $uid = $x['uid'] ?? null;
            $ev[] = ['device_id' => $d->id, 'ts' => $ts, 'received_at' => $ts, 'type' => $type, 'status' => $status,
                'direction' => $x['direction'] ?? null, 'uid' => $uid, 'card_name' => $uid ? ($names[$uid] ?? null) : null,
                'detail' => $x['detail'] ?? null, 'duration_ms' => $x['durationMs'] ?? null];
        };

        for ($day = $start - (($start + $tz) % 86400000); $day < $now; $day += 86400000) {
            $dow = (intdiv($day + $tz, 86400000) + 4) % 7;
            $weekend = $dow === 0 || $dow === 6;
            $plan = [];
            foreach ($users as $u) {
                if ($this->rnd(0, 1) > ($weekend ? 0.12 : 0.85)) continue;
                $in = $day + $this->gauss($weekend ? 10 : 8.2, 0.9) * 3600000;
                $out = $in + max(1.5, $this->gauss(7, 1.8)) * 3600000;
                $plan[] = [$in, 'MASUK', $u]; $plan[] = [$out, 'KELUAR', $u];
                if (!$weekend && $this->rnd(0, 1) < 0.5) {
                    $l = $day + $this->gauss(12.1, 0.3) * 3600000;
                    if ($l > $in + 1800000 && $l < $out - 3600000) { $plan[] = [$l, 'KELUAR', $u]; $plan[] = [$l + $this->rnd(30, 70) * 60000, 'MASUK', $u]; }
                }
            }
            if ($this->rnd(0, 1) < 0.6) for ($k = 0; $k < mt_rand(1, 3); $k++) $plan[] = [$day + $this->rnd(8, 18) * 3600000, 'DITOLAK', $this->pick($intruders)];
            if ($this->rnd(0, 1) < 0.15) $plan[] = [$day + $this->rnd(7, 19) * 3600000, 'LEFT', null];
            if ($this->rnd(0, 1) < 0.05) $plan[] = [$day + $this->rnd(19, 23) * 3600000, 'FORCED', null];
            if ($this->rnd(0, 1) < 0.04) $plan[] = [$day + $this->rnd(19, 23) * 3600000, 'BRUTE', $this->pick($intruders)];
            if (!$weekend && $this->rnd(0, 1) < 0.3) $plan[] = [$day + $this->rnd(9, 15) * 3600000, 'REMOTE', null];
            usort($plan, fn ($a, $b) => $a[0] <=> $b[0]);

            $inside = [];
            $dayStartOcc = $occ;
            foreach ($plan as [$t0, $kind, $u]) {
                $t = (int) $t0;
                if ($t > $now - 60000) break;
                $S = function (int $dt, array $o = []) use (&$tel, $t, &$occ) { $tel[] = $this->base(array_merge(['ts' => $t + $dt, 'occupancy' => $occ], $o)); };
                if ($kind === 'MASUK' || $kind === 'KELUAR') {
                    if ($kind === 'KELUAR' && empty($inside[$u])) continue;
                    $E($t, 'ACCESS', $kind, ['direction' => $kind, 'uid' => $u]);
                    $S(0, ['relay' => true, 'locked' => false, 'buzzer' => true, 'ledGreen' => true]);
                    $open = (int) ($this->rnd(3, 9) * 1000 * ($this->rnd(0, 1) < 0.05 ? 4 : 1));
                    $E($t + 1500, 'DOOR', 'OPEN');
                    if ($kind === 'MASUK') { $occ++; $inside[$u] = true; } else { $occ = max(0, $occ - 1); unset($inside[$u]); }
                    $S(1500, ['doorOpen' => true, 'locked' => false]);
                    $E($t + 1500 + $open, 'DOOR', 'CLOSE', ['durationMs' => $open]);
                    $S(1500 + $open);
                } elseif ($kind === 'DITOLAK') {
                    $E($t, 'ACCESS', 'DITOLAK', ['direction' => 'MASUK', 'uid' => $u]);
                    $S(0, ['buzzer' => true, 'ledRed' => true, 'failCount' => 1]); $S(3000);
                } elseif ($kind === 'BRUTE') {
                    for ($k = 0; $k < 3; $k++) $E($t + $k * 4000, 'ACCESS', 'DITOLAK', ['direction' => 'MASUK', 'uid' => $u]);
                    $E($t + 8100, 'ALARM', 'BRUTE_FORCE', ['detail' => '3x kartu ditolak']);
                    $S(8100, ['alarm' => true, 'alarmType' => 'BRUTE_FORCE', 'buzzer' => true, 'readersBlocked' => true, 'failCount' => 3, 'ledRed' => true]);
                    $E($t + 38100, 'ALARM_RESET', 'AUTO', ['detail' => 'blokir reader selesai']); $S(38100);
                } elseif ($kind === 'LEFT') {
                    $open = (int) ($this->rnd(40, 180) * 1000);
                    $E($t + 1500, 'DOOR', 'OPEN');
                    if ($users) { $who = $this->pick($users); $E($t, 'ACCESS', 'MASUK', ['direction' => 'MASUK', 'uid' => $who]); $occ++; $inside[$who] = true; }
                    $S(1500, ['doorOpen' => true]);
                    $E($t + 31500, 'ALARM', 'DOOR_LEFT_OPEN', ['detail' => 'pintu terbuka > 30 dtk']);
                    $S(31500, ['doorOpen' => true, 'alarm' => true, 'alarmType' => 'DOOR_LEFT_OPEN', 'buzzer' => true]);
                    $E($t + 1500 + $open, 'DOOR', 'CLOSE', ['durationMs' => $open]);
                    $E($t + 1500 + $open, 'ALARM_RESET', 'AUTO', ['detail' => 'pintu ditutup']); $S(1500 + $open);
                } elseif ($kind === 'FORCED') {
                    $close = (int) ($this->rnd(10, 60) * 1000);
                    $E($t, 'DOOR', 'OPEN');
                    $E($t + 200, 'ALARM', 'FORCED_OPEN', ['detail' => 'pintu terbuka tanpa akses sah']);
                    $S(200, ['doorOpen' => true, 'alarm' => true, 'alarmType' => 'FORCED_OPEN', 'buzzer' => true]);
                    $E($t + $close, 'DOOR', 'CLOSE', ['durationMs' => $close]);
                    $S($close, ['alarm' => true, 'alarmType' => 'FORCED_OPEN', 'buzzer' => true]);
                    $E($t + $close + 120000, 'ALARM_RESET', 'REMOTE', ['detail' => 'Admin']); $S($close + 120000);
                } elseif ($kind === 'REMOTE') {
                    $E($t, 'ACCESS', 'REMOTE', ['detail' => 'Admin']);
                    $S(0, ['relay' => true, 'locked' => false]);
                    $E($t + 2500, 'DOOR', 'OPEN'); $S(2500, ['doorOpen' => true]);
                    $E($t + 8000, 'DOOR', 'CLOSE', ['durationMs' => 5500]); $S(8000);
                }
            }
            // telemetri periodik tiap 5 menit (jumlah orang mengikuti perubahan terakhir hari itu)
            $todays = array_values(array_filter($tel, fn ($x) => $x['ts'] >= $day && $x['ts'] < $day + 86400000));
            usort($todays, fn ($a, $b) => $a['ts'] <=> $b['ts']);
            $o = $dayStartOcc; $vi = 0;
            for ($t = $day; $t < min($day + 86400000, $now - 60000); $t += 300000) {
                while ($vi < count($todays) && $todays[$vi]['ts'] <= $t) $o = $todays[$vi++]['occupancy'];
                $tel[] = $this->base(['ts' => $t, 'occupancy' => $o]);
            }
        }
        usort($tel, fn ($a, $b) => $a['ts'] <=> $b['ts']);
        $rows = array_map(fn ($s) => [
            'device_id' => $d->id, 'ts' => $s['ts'], 'lock_state' => $s['locked'], 'relay' => $s['relay'], 'door_open' => $s['doorOpen'],
            'buzzer' => $s['buzzer'], 'led_green' => $s['ledGreen'], 'led_red' => $s['ledRed'], 'alarm' => $s['alarm'], 'alarm_type' => $s['alarmType'],
            'occupancy' => $s['occupancy'], 'fail_count' => $s['failCount'], 'readers_blocked' => $s['readersBlocked'],
            'reader_in_ok' => true, 'reader_out_ok' => true, 'rssi' => $s['rssi'], 'uptime_s' => null, 'free_heap' => $s['freeHeap'], 'queue_len' => 0,
        ], $tel);
        DB::transaction(function () use ($ev, $rows) {
            foreach (array_chunk($ev, 200) as $c) DB::table('door_events')->insert($c);
            foreach (array_chunk($rows, 200) as $c) DB::table('telemetry')->insert($c);
        });
        if ($tel) DB::table('devices')->where('id', $d->id)->update(['state' => json_encode(end($tel))]);
        $this->line("  {$d->id}: " . count($ev) . ' event, ' . count($tel) . ' telemetri');
    }

    // ------------------------------------------------------------------ live: node virtual via HTTP
    private function live(array $devices, array $cards): void
    {
        $url = rtrim($this->option('url') ?: config('app.url'), '/');
        $speed = max(0.1, (float) $this->option('speed'));
        $this->info("Simulasi live ke {$url}. Ctrl+C untuk berhenti.");
        $nodes = [];
        foreach ($devices as $i => $d) {
            $nodes[] = (object) ['d' => $d, 'users' => $this->usersFor($cards, $i, count($devices)), 'inside' => [], 'wl' => [], 'cfg' => SmartDoor::defaults(),
                'sv' => null, 's' => $this->base(['occupancy' => 0]), 'boot' => microtime(true), 'next' => microtime(true) + $this->rnd(1, 5),
                'lastPoll' => 0, 'lastTel' => 0, 'dirty' => true, 'relayUntil' => 0, 'openAt' => 0, 'closeAt' => 0, 'openedAt' => 0,
                'pDir' => null, 'pUid' => null, 'leftSent' => false, 'blockedUntil' => 0];
        }
        $req = fn ($n, $method, $path, $body = null) => rescue(function () use ($n, $method, $path, $body, $url) {
            $http = Http::timeout(5)->acceptJson()->withHeaders(['X-Device-Key' => $n->d->api_key]);
            $res = $method === 'GET' ? $http->get($url . $path) : $http->post($url . $path, $body ?? []);
            return $res->successful() ? $res->json() : null;
        }, null, false);
        $event = function ($n, array $e) use ($req) { $this->reply($n, $req($n, 'POST', '/api/v1/events', [array_merge(['ts' => SmartDoor::now()], $e)])); };
        $set = function ($n, array $o) { $n->s = array_merge($n->s, $o); $n->dirty = true; };
        $log = fn ($n, $m) => $this->line(date('H:i:s') . ' ' . str_pad($n->d->id, 12) . ' ' . $m);

        foreach ($nodes as $n) { $this->sync($n, $req); $event($n, ['type' => 'SYSTEM', 'status' => 'BOOT', 'detail' => 'simulator']); }

        while (true) {
            $now = microtime(true);
            foreach ($nodes as $n) {
                // perintah dari web
                if ($now - $n->lastPoll >= 1) {
                    $n->lastPoll = $now;
                    $this->reply($n, $req($n, 'GET', '/api/v1/commands'));
                    if ($n->sv === 'resync') $this->sync($n, $req);
                    foreach ($n->cmds ?? [] as $c) {
                        $log($n, 'perintah ' . $c['type']);
                        $detail = 'ok';
                        if ($c['type'] === 'UNLOCK') {
                            $event($n, ['type' => 'ACCESS', 'status' => 'REMOTE', 'detail' => 'cmd:' . $c['id']]);
                            $n->pDir = null; $n->pUid = null; $n->relayUntil = $now + $n->cfg['unlockMs'] / 1000; $n->openAt = $now + $this->rnd(1, 3);
                            $set($n, ['relay' => true, 'locked' => false]); $detail = 'kunci dibuka';
                        } elseif ($c['type'] === 'RESET_ALARM') {
                            if ($n->s['alarm'] || $n->s['readersBlocked']) $event($n, ['type' => 'ALARM_RESET', 'status' => 'REMOTE', 'detail' => 'cmd:' . $c['id']]);
                            $n->blockedUntil = 0; $set($n, ['alarm' => false, 'alarmType' => null, 'buzzer' => false, 'readersBlocked' => false, 'failCount' => 0, 'ledRed' => false]);
                            $detail = 'alarm dihentikan';
                        } elseif ($c['type'] === 'SYNC') { $this->sync($n, $req); $detail = 'tersinkron'; }
                        $req($n, 'POST', "/api/v1/commands/{$c['id']}/ack", ['ok' => true, 'detail' => $detail]);
                    }
                    $n->cmds = [];
                }
                // pintu dibuka setelah akses sah
                if ($n->openAt && $now >= $n->openAt && $n->s['relay']) {
                    $n->openAt = 0; $n->openedAt = $now; $n->leftSent = false;
                    $n->closeAt = $now + ($this->rnd(0, 1) < 0.08 ? $this->rnd(35, 60) : $this->rnd(3, 8)) / $speed;
                    $event($n, ['type' => 'DOOR', 'status' => 'OPEN']);
                    if ($n->pDir === 'MASUK') { $n->s['occupancy']++; $n->inside[$n->pUid] = true; }
                    if ($n->pDir === 'KELUAR') { $n->s['occupancy'] = max(0, $n->s['occupancy'] - 1); unset($n->inside[$n->pUid]); }
                    $n->pDir = null; $n->pUid = null;
                    $set($n, ['doorOpen' => true, 'relay' => false, 'ledGreen' => false]);
                }
                // akses sah tapi pintu tidak dibuka -> batal
                if ($n->s['relay'] && !$n->s['doorOpen'] && $now >= $n->relayUntil) {
                    if ($n->pUid) $event($n, ['type' => 'ACCESS', 'status' => 'BATAL', 'direction' => $n->pDir, 'uid' => $n->pUid, 'detail' => 'pintu tidak dibuka']);
                    $n->openAt = 0; $n->pDir = null; $n->pUid = null; $set($n, ['relay' => false, 'locked' => true, 'ledGreen' => false]);
                }
                // pintu ditutup
                if ($n->s['doorOpen'] && $n->closeAt && $now >= $n->closeAt) {
                    $n->closeAt = 0;
                    $event($n, ['type' => 'DOOR', 'status' => 'CLOSE', 'durationMs' => (int) (($now - $n->openedAt) * 1000)]);
                    if ($n->s['alarm'] && $n->s['alarmType'] === 'DOOR_LEFT_OPEN') {
                        $event($n, ['type' => 'ALARM_RESET', 'status' => 'AUTO', 'detail' => 'pintu ditutup']);
                        $set($n, ['alarm' => false, 'alarmType' => null, 'buzzer' => false]);
                    }
                    $set($n, ['doorOpen' => false, 'locked' => true]);
                }
                // pintu lupa ditutup
                if ($n->s['doorOpen'] && !$n->leftSent && !$n->s['alarm'] && $now - $n->openedAt > $n->cfg['leftOpenAlarmS']) {
                    $n->leftSent = true;
                    $event($n, ['type' => 'ALARM', 'status' => 'DOOR_LEFT_OPEN', 'detail' => "pintu terbuka > {$n->cfg['leftOpenAlarmS']} dtk"]);
                    $set($n, ['alarm' => true, 'alarmType' => 'DOOR_LEFT_OPEN', 'buzzer' => true]);
                }
                // blokir reader selesai
                if ($n->blockedUntil && $now >= $n->blockedUntil) {
                    $n->blockedUntil = 0;
                    $event($n, ['type' => 'ALARM_RESET', 'status' => 'AUTO', 'detail' => 'blokir reader selesai']);
                    $set($n, ['alarm' => false, 'alarmType' => null, 'buzzer' => false, 'readersBlocked' => false, 'failCount' => 0, 'ledRed' => false]);
                }
                // aktivitas acak
                if ($now >= $n->next && !$n->s['relay'] && !$n->s['doorOpen']) {
                    $n->next = $now + $this->rnd(6, 20) / $speed;
                    $this->step($n, $event, $set, $log, $now);
                }
                // telemetri: saat state berubah + periodik
                if ($n->dirty || $now - $n->lastTel >= $n->cfg['telemetryIntervalS']) {
                    $n->dirty = false; $n->lastTel = $now;
                    $n->s['uptime'] = (int) ($now - $n->boot); $n->s['rssi'] = mt_rand(-70, -50);
                    $this->reply($n, $req($n, 'POST', '/api/v1/telemetry', array_merge(['ts' => SmartDoor::now()], $n->s)));
                }
            }
            usleep(200000);
        }
    }

    private function step(object $n, callable $event, callable $set, callable $log, float $now): void
    {
        if ($n->s['readersBlocked']) return;
        $r = $this->rnd(0, 1);
        $users = array_values(array_filter($n->users, fn ($u) => isset($n->wl[$u])));
        if ($r < 0.80 && $users) {
            $inside = array_keys($n->inside);
            if ($inside && $this->rnd(0, 1) < 0.45) { $uid = $this->pick($inside); $dir = 'KELUAR'; }
            else { $out = array_values(array_diff($users, $inside)); $uid = $out ? $this->pick($out) : $this->pick($users); $dir = 'MASUK'; }
            $event($n, ['type' => 'ACCESS', 'status' => $dir, 'direction' => $dir, 'uid' => $uid]);
            $log($n, str_pad($dir, 8) . $n->wl[$uid]);
            $n->pDir = $dir; $n->pUid = $uid;
            $n->relayUntil = $now + $n->cfg['unlockMs'] / 1000;
            $n->openAt = $this->rnd(0, 1) < 0.93 ? $now + $this->rnd(0.8, 3) : 0;
            $set($n, ['relay' => true, 'locked' => false, 'ledGreen' => true, 'failCount' => 0]);
        } elseif ($r < 0.95) {
            $uid = $this->rnd(0, 1) < 0.3 ? 'DEADBEEF' : $this->hexUid();
            $n->s['failCount']++;
            $event($n, ['type' => 'ACCESS', 'status' => 'DITOLAK', 'direction' => 'MASUK', 'uid' => $uid]);
            $log($n, 'DITOLAK  ' . $uid);
            if ($n->s['failCount'] >= $n->cfg['maxFail']) {
                $event($n, ['type' => 'ALARM', 'status' => 'BRUTE_FORCE', 'detail' => "{$n->s['failCount']}x kartu ditolak"]);
                $n->blockedUntil = $now + $n->cfg['blockS'];
                $set($n, ['alarm' => true, 'alarmType' => 'BRUTE_FORCE', 'buzzer' => true, 'readersBlocked' => true, 'ledRed' => true]);
            } else $set($n, []);
        } else {
            $n->openedAt = $now; $n->leftSent = false; $n->closeAt = $now + $this->rnd(5, 20);
            $event($n, ['type' => 'DOOR', 'status' => 'OPEN']);
            $event($n, ['type' => 'ALARM', 'status' => 'FORCED_OPEN', 'detail' => 'pintu terbuka tanpa akses sah']);
            $log($n, 'ALARM    pintu dibuka paksa');
            $set($n, ['doorOpen' => true, 'alarm' => true, 'alarmType' => 'FORCED_OPEN', 'buzzer' => true]);
        }
    }

    private function reply(object $n, ?array $r): void
    {
        if (!$r) return;
        $n->cmds = array_merge($n->cmds ?? [], $r['commands'] ?? []);
        if (!empty($r['syncVersion']) && $r['syncVersion'] !== $n->sv && $n->sv !== null) $n->sv = 'resync';
    }

    private function sync(object $n, callable $req): void
    {
        $r = $req($n, 'GET', '/api/v1/sync');
        if (!$r) { $this->warn("{$n->d->id}: server tidak bisa dihubungi"); $n->sv = 'resync'; return; }
        $n->wl = collect($r['cards'])->pluck('name', 'uid')->all();
        $n->cfg = $r['config'];
        $n->sv = $r['syncVersion'];
    }
}
