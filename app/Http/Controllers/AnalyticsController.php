<?php

namespace App\Http\Controllers;

use App\Services\SmartDoor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Modul analitik. Pengelompokan jam/hari dihitung dengan aritmetika bilangan bulat
 * sehingga query berjalan sama di SQLite, MySQL/MariaDB, maupun PostgreSQL.
 */
class AnalyticsController extends Controller
{
    public function __invoke(Request $r): JsonResponse
    {
        $to = (int) $r->query('to', SmartDoor::now());
        $from = (int) $r->query('from', $to - 7 * 86400000);
        $dev = $r->query('device') ?: null;
        $D = SmartDoor::idiv();
        $tz = SmartDoor::tzSec();
        $local = "(ts{$D}1000 + {$tz})";               // detik lokal sejak epoch
        $hour = "(({$local}{$D}3600) % 24)";
        $day = "({$local}{$D}86400)";                  // indeks hari lokal
        $dow = "((({$local}{$D}86400) + 4) % 7)";       // 0 = Minggu (1 Jan 1970 = Kamis)

        $ev = function () use ($from, $to, $dev) {
            $q = DB::table('door_events')->whereBetween('ts', [$from, $to]);
            if ($dev) $q->where('device_id', $dev);
            return $q;
        };
        $int = fn ($v) => (int) $v;
        $sum = fn ($cond, $as) => "SUM(CASE WHEN {$cond} THEN 1 ELSE 0 END) as {$as}";

        $counts = $ev()->where('type', 'ACCESS')->groupBy('status')->select('status', DB::raw('COUNT(*) as n'))
            ->pluck('n', 'status')->map($int)->all();
        $alarms = $ev()->where('type', 'ALARM')->groupBy('status')->select('status', DB::raw('COUNT(*) as n'))
            ->orderByDesc('n')->get()->map(fn ($a) => ['type' => $a->status, 'n' => (int) $a->n])->all();
        $door = $ev()->where('type', 'DOOR')->where('status', 'CLOSE')->whereNotNull('duration_ms')
            ->selectRaw('AVG(duration_ms) as avg_ms, MAX(duration_ms) as max_ms')->first();
        $uniq = (int) $ev()->where('type', 'ACCESS')->whereIn('status', ['MASUK', 'KELUAR'])->distinct()->count('uid');

        // per jam
        $hourly = [];
        for ($h = 0; $h < 24; $h++) $hourly[$h] = ['hour' => $h, 'MASUK' => 0, 'KELUAR' => 0, 'DITOLAK' => 0];
        $rows = $ev()->where('type', 'ACCESS')->whereIn('status', ['MASUK', 'KELUAR', 'DITOLAK'])
            ->selectRaw("{$hour} as h, status, COUNT(*) as n")->groupByRaw("{$hour}, status")->get();
        foreach ($rows as $x) $hourly[(int) $x->h][$x->status] = (int) $x->n;

        // per hari (semua tanggal pada rentang tetap ditampilkan walau nol)
        $daily = [];
        $firstDay = intdiv(intdiv($from, 1000) + $tz, 86400);
        $lastDay = intdiv(intdiv($to, 1000) + $tz, 86400);
        for ($d = $firstDay; $d <= $lastDay; $d++) {
            $daily[$d] = ['day' => gmdate('Y-m-d', $d * 86400), 'MASUK' => 0, 'KELUAR' => 0, 'DITOLAK' => 0, 'REMOTE' => 0, 'ALARM' => 0];
        }
        $rows = $ev()->where(fn ($w) => $w->where('type', 'ALARM')
                ->orWhere(fn ($a) => $a->where('type', 'ACCESS')->whereIn('status', ['MASUK', 'KELUAR', 'DITOLAK', 'REMOTE'])))
            ->selectRaw("{$day} as d, CASE WHEN type = 'ALARM' THEN 'ALARM' ELSE status END as k, COUNT(*) as n")
            ->groupByRaw("{$day}, CASE WHEN type = 'ALARM' THEN 'ALARM' ELSE status END")->get();
        foreach ($rows as $x) if (isset($daily[(int) $x->d])) $daily[(int) $x->d][$x->k] = (int) $x->n;

        // heatmap hari x jam (MASUK)
        $heat = array_fill(0, 7, array_fill(0, 24, 0));
        $rows = $ev()->where('type', 'ACCESS')->where('status', 'MASUK')
            ->selectRaw("{$dow} as dw, {$hour} as h, COUNT(*) as n")->groupByRaw("{$dow}, {$hour}")->get();
        foreach ($rows as $x) $heat[(int) $x->dw][(int) $x->h] = (int) $x->n;

        $topUsers = $ev()->where('type', 'ACCESS')->whereIn('status', ['MASUK', 'KELUAR'])->whereNotNull('uid')
            ->groupBy('uid')
            ->selectRaw('uid, MAX(card_name) as name, MAX(ts) as last_ts, ' . $sum("status = 'MASUK'", 'masuk') . ', ' . $sum("status = 'KELUAR'", 'keluar'))
            ->orderByRaw('COUNT(*) DESC')->limit(10)->get()
            ->map(fn ($u) => ['uid' => $u->uid, 'name' => $u->name ?: $u->uid, 'masuk' => (int) $u->masuk, 'keluar' => (int) $u->keluar, 'last' => (int) $u->last_ts])->all();

        $deniedUids = $ev()->where('type', 'ACCESS')->where('status', 'DITOLAK')->whereNotNull('uid')->groupBy('uid')
            ->select('uid', DB::raw('MAX(card_name) as name'), DB::raw('COUNT(*) as n'), DB::raw('MAX(ts) as last_ts'))
            ->orderByDesc('n')->limit(10)->get()
            ->map(fn ($u) => ['uid' => $u->uid, 'name' => $u->name ?: '(tidak terdaftar)', 'n' => (int) $u->n, 'last' => (int) $u->last_ts])->all();

        $byDevice = $ev()->groupBy('device_id')
            ->selectRaw(implode(', ', [
                'device_id',
                $sum("type = 'ACCESS' AND status = 'MASUK'", 'masuk'),
                $sum("type = 'ACCESS' AND status = 'KELUAR'", 'keluar'),
                $sum("type = 'ACCESS' AND status = 'DITOLAK'", 'ditolak'),
                $sum("type = 'ACCESS' AND status = 'REMOTE'", 'remote'),
                $sum("type = 'ALARM'", 'alarm'),
                "AVG(CASE WHEN type = 'DOOR' AND status = 'CLOSE' THEN duration_ms END) as avg_open",
            ]))->orderBy('device_id')->get()
            ->map(fn ($x) => ['deviceId' => $x->device_id, 'masuk' => (int) $x->masuk, 'keluar' => (int) $x->keluar, 'ditolak' => (int) $x->ditolak,
                'remote' => (int) $x->remote, 'alarm' => (int) $x->alarm, 'avgOpenMs' => $x->avg_open !== null ? (float) $x->avg_open : null])->all();

        // ketersediaan perangkat: % slot 5 menit yang berisi telemetri, sejak telemetri pertama pada rentang
        $slot = 300000;
        $tq = DB::table('telemetry')->whereBetween('ts', [$from, $to]);
        if ($dev) $tq->where('device_id', $dev);
        $availability = $tq->groupBy('device_id')
            ->selectRaw("device_id, COUNT(DISTINCT ts{$D}{$slot}) as slots, MIN(ts) as first_ts")->get()
            ->map(fn ($x) => ['deviceId' => $x->device_id,
                'pct' => min(100, (int) $x->slots / max(1, (int) ceil(($to - max($from, (int) $x->first_ts)) / $slot)) * 100)])->all();

        $granted = ($counts['MASUK'] ?? 0) + ($counts['KELUAR'] ?? 0);
        $taps = $granted + ($counts['DITOLAK'] ?? 0);
        $peak = null; $peakN = 0;
        foreach ($hourly as $h) if ($h['MASUK'] + $h['KELUAR'] > $peakN) { $peakN = $h['MASUK'] + $h['KELUAR']; $peak = $h['hour']; }

        return response()->json([
            'range' => ['from' => $from, 'to' => $to, 'device' => $dev],
            'kpi' => [
                'masuk' => $counts['MASUK'] ?? 0, 'keluar' => $counts['KELUAR'] ?? 0, 'ditolak' => $counts['DITOLAK'] ?? 0,
                'remote' => $counts['REMOTE'] ?? 0, 'batal' => $counts['BATAL'] ?? 0,
                'alarms' => array_sum(array_column($alarms, 'n')), 'uniqueUsers' => $uniq,
                'denialRate' => $taps ? ($counts['DITOLAK'] ?? 0) / $taps : 0,
                'avgDoorOpenS' => $door && $door->avg_ms ? $door->avg_ms / 1000 : 0,
                'maxDoorOpenS' => $door && $door->max_ms ? $door->max_ms / 1000 : 0,
                'peakHour' => $peak,
            ],
            'hourly' => array_values($hourly),
            'daily' => array_values($daily),
            'heat' => $heat,
            'alarms' => $alarms,
            'topUsers' => $topUsers,
            'deniedUids' => $deniedUids,
            'byDevice' => $byDevice,
            'availability' => $availability,
        ]);
    }
}
