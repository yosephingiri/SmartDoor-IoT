<?php

namespace App\Http\Controllers;

use App\Services\SmartDoor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Endpoint JSON untuk dashboard web (butuh login). */
class PanelController extends Controller
{
    public function dashboard()
    {
        return view('smartdoor.dashboard', ['user' => auth()->user()]);
    }

    public function me(): JsonResponse
    {
        return response()->json(['user' => auth()->user()->name]);
    }

    private function devices(): array
    {
        return DB::table('devices')->orderBy('id')->get()->map(fn ($d) => SmartDoor::deviceRow($d))->all();
    }

    private function dayStart(): int
    {
        $tz = SmartDoor::tzSec() * 1000;
        $t = SmartDoor::now() + $tz;
        return $t - ($t % 86400000) - $tz;
    }

    public function overview(): JsonResponse
    {
        SmartDoor::expireCommands();
        $dayStart = $this->dayStart();
        $today = DB::table('door_events')->where('ts', '>=', $dayStart)->where('type', 'ACCESS')
            ->groupBy('status')->select('status', DB::raw('COUNT(*) as n'))->pluck('n', 'status')
            ->map(fn ($n) => (int) $n);
        return response()->json([
            'devices' => $this->devices(),
            'today' => (object) $today->all(),
            'alarmsToday' => DB::table('door_events')->where('ts', '>=', $dayStart)->where('type', 'ALARM')->count(),
            'recent' => DB::table('door_events')->orderByDesc('ts')->orderByDesc('id')->limit(30)->get()->map(fn ($e) => SmartDoor::eventOut($e)),
            'lastId' => (int) DB::table('door_events')->max('id'),
            'dayStart' => $dayStart,
            'tzOffsetMin' => config('smartdoor.tz_offset_min'),
        ]);
    }

    /** Pengganti realtime: browser memanggil ini tiap 2 detik untuk event baru & state perangkat. */
    public function live(Request $r): JsonResponse
    {
        $after = (int) $r->query('after', 0);
        if ($after <= 0) {   // panggilan pertama: cukup kirim posisi terakhir
            return response()->json(['events' => [], 'lastId' => (int) DB::table('door_events')->max('id'), 'devices' => $this->devices()]);
        }
        $events = DB::table('door_events')->where('id', '>', $after)->orderBy('id')->limit(200)->get()
            ->map(fn ($e) => SmartDoor::eventOut($e));
        return response()->json([
            'events' => $events,
            'lastId' => $events->isNotEmpty() ? $events->last()['id'] : $after,
            'devices' => $this->devices(),
        ]);
    }

    public function presence(): JsonResponse
    {
        $latest = DB::table('door_events')->where('type', 'ACCESS')->whereIn('status', ['MASUK', 'KELUAR'])
            ->whereNotNull('uid')->groupBy('device_id', 'uid')->select(DB::raw('MAX(id) as mid'));
        $rows = DB::table('door_events as e')->joinSub($latest, 'x', 'e.id', '=', 'x.mid')
            ->where('e.status', 'MASUK')->where('e.ts', '>', SmartDoor::now() - 7 * 86400000)
            ->orderByDesc('e.ts')->get(['e.device_id', 'e.uid', 'e.card_name', 'e.ts']);
        return response()->json($rows->map(fn ($r) => ['deviceId' => $r->device_id, 'uid' => $r->uid, 'name' => $r->card_name, 'since' => (int) $r->ts]));
    }

    // ------------------------------------------------------------------ log kejadian
    private function filtered(Request $r)
    {
        $q = DB::table('door_events');
        if ($r->filled('device')) $q->where('device_id', $r->query('device'));
        if ($r->filled('type')) $q->where('type', strtoupper($r->query('type')));
        if ($r->filled('status')) $q->where('status', strtoupper($r->query('status')));
        if ($r->filled('from')) $q->where('ts', '>=', (int) $r->query('from'));
        if ($r->filled('to')) $q->where('ts', '<=', (int) $r->query('to'));
        if ($r->filled('q')) {
            $s = '%' . $r->query('q') . '%';
            $q->where(fn ($w) => $w->where('uid', 'like', $s)->orWhere('card_name', 'like', $s)->orWhere('detail', 'like', $s));
        }
        return $q;
    }

    public function events(Request $r): JsonResponse
    {
        $limit = min((int) $r->query('limit', 50), 500);
        $q = $this->filtered($r);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('ts')->orderByDesc('id')->offset((int) $r->query('offset', 0))->limit($limit)->get();
        return response()->json(['total' => $total, 'rows' => $rows->map(fn ($e) => SmartDoor::eventOut($e))]);
    }

    public function eventsCsv(Request $r): StreamedResponse
    {
        $q = $this->filtered($r)->orderByDesc('ts')->limit(100000);
        $tz = SmartDoor::tzSec();
        return response()->streamDownload(function () use ($q, $tz) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['waktu_wib', 'perangkat', 'tipe', 'status', 'arah', 'uid', 'nama', 'keterangan', 'durasi_ms']);
            foreach ($q->cursor() as $e) {
                fputcsv($out, [gmdate('Y-m-d H:i:s', intdiv((int) $e->ts, 1000) + $tz), $e->device_id, $e->type, $e->status, $e->direction, $e->uid, $e->card_name, $e->detail, $e->duration_ms]);
            }
            fclose($out);
        }, 'log-akses.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    // ------------------------------------------------------------------ telemetri
    public function telemetry(Request $r): JsonResponse
    {
        $r->validate(['device' => 'required']);
        $to = (int) $r->query('to', SmartDoor::now());
        $from = (int) $r->query('from', $to - 86400000);
        $rows = DB::table('telemetry')->where('device_id', $r->query('device'))->whereBetween('ts', [$from, $to])
            ->orderBy('ts')->limit(20000)
            ->get(['ts', 'lock_state', 'relay', 'door_open', 'buzzer', 'alarm', 'alarm_type', 'occupancy', 'fail_count', 'readers_blocked', 'reader_in_ok', 'reader_out_ok', 'rssi', 'uptime_s', 'free_heap']);
        return response()->json($rows->map(function ($t) {
            $a = (array) $t;
            foreach ($a as $k => $v) if ($v !== null && $k !== 'alarm_type') $a[$k] = (int) $v;
            return $a;
        }));
    }

    // ------------------------------------------------------------------ perintah jarak jauh
    public function sendCommand(Request $r, string $id): JsonResponse
    {
        $type = strtoupper((string) $r->json('type'));
        if (!in_array($type, SmartDoor::COMMAND_TYPES, true)) return response()->json(['error' => 'Jenis perintah tidak dikenal'], 400);
        if (!DB::table('devices')->where('id', $id)->exists()) return response()->json(['error' => 'Perangkat tidak ditemukan'], 404);
        $cid = DB::table('device_commands')->insertGetId([
            'device_id' => $id, 'type' => $type, 'issued_by' => auth()->user()->name, 'created_at' => SmartDoor::now(),
        ]);
        return response()->json(['id' => $cid, 'status' => 'pending'], 201);
    }

    public function commands(Request $r): JsonResponse
    {
        SmartDoor::expireCommands();
        $q = DB::table('device_commands')->orderByDesc('id')->limit(50);
        if ($r->filled('device')) $q->where('device_id', $r->query('device'));
        return response()->json($q->get()->map(function ($c) {
            $c->id = (int) $c->id;
            foreach (['created_at', 'sent_at', 'acked_at'] as $k) $c->$k = $c->$k ? (int) $c->$k : null;
            return $c;
        }));
    }
}
