<?php

namespace App\Http\Controllers;

use App\Services\SmartDoor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Manajemen kartu RFID (whitelist terpusat yang disinkron ke setiap ESP32). */
class CardController extends Controller
{
    private function row(object $c): array
    {
        $last = DB::table('door_events')->where('uid', $c->uid)->where('type', 'ACCESS')->orderByDesc('ts')->first(['ts', 'device_id', 'status']);
        return [
            'uid' => $c->uid, 'name' => $c->name, 'role' => $c->role,
            'active' => (bool) $c->active, 'allDevices' => (bool) $c->all_devices,
            'devices' => DB::table('card_access')->where('uid', $c->uid)->pluck('device_id')->all(),
            'createdAt' => (int) $c->created_at,
            'lastAccess' => $last ? ['ts' => (int) $last->ts, 'device_id' => $last->device_id, 'status' => $last->status] : null,
        ];
    }

    public function index(): JsonResponse
    {
        return response()->json(DB::table('cards')->orderBy('name')->get()->map(fn ($c) => $this->row($c)));
    }

    /** UID tak dikenal yang pernah ditempelkan -> memudahkan registrasi kartu baru. */
    public function unknown(): JsonResponse
    {
        $rows = DB::table('door_events')
            ->where('type', 'ACCESS')->where('status', 'DITOLAK')->whereNotNull('uid')
            ->whereNotIn('uid', DB::table('cards')->select('uid'))
            ->groupBy('uid')
            ->select('uid', DB::raw('COUNT(*) as attempts'), DB::raw('MAX(ts) as last_ts'), DB::raw('MAX(device_id) as dev_id'))
            ->orderByDesc('last_ts')->limit(20)->get();
        return response()->json($rows->map(fn ($r) => ['uid' => $r->uid, 'attempts' => (int) $r->attempts, 'lastTs' => (int) $r->last_ts, 'deviceId' => $r->dev_id]));
    }

    private function saveAccess(string $uid, Request $r): void
    {
        $devs = $r->json('devices');
        if (!is_array($devs)) return;
        DB::table('card_access')->where('uid', $uid)->delete();
        $valid = DB::table('devices')->whereIn('id', $devs)->pluck('id');
        foreach ($valid as $d) DB::table('card_access')->insert(['uid' => $uid, 'device_id' => $d]);
    }

    public function store(Request $r): JsonResponse
    {
        $uid = SmartDoor::normUid($r->json('uid'));
        if (strlen($uid) < 8 || !$r->json('name')) return response()->json(['error' => 'UID (min. 4 byte hex) dan nama wajib diisi'], 400);
        if (DB::table('cards')->where('uid', $uid)->exists()) return response()->json(['error' => 'UID sudah terdaftar'], 409);
        DB::transaction(function () use ($uid, $r) {
            DB::table('cards')->insert([
                'uid' => $uid, 'name' => (string) $r->json('name'), 'role' => (string) ($r->json('role') ?? ''),
                'active' => $r->json('active') !== false, 'all_devices' => $r->json('allDevices') !== false,
                'created_at' => SmartDoor::now(),
            ]);
            $this->saveAccess($uid, $r);
            // isi nama pada log lama UID ini (kartu yang sebelumnya ditolak lalu didaftarkan)
            DB::table('door_events')->where('uid', $uid)->whereNull('card_name')->update(['card_name' => (string) $r->json('name')]);
            SmartDoor::bumpWhitelist();
        });
        return response()->json($this->row(DB::table('cards')->where('uid', $uid)->first()), 201);
    }

    public function update(Request $r, string $uid): JsonResponse
    {
        $uid = SmartDoor::normUid($uid);
        $c = DB::table('cards')->where('uid', $uid)->first();
        if (!$c) return response()->json(['error' => 'Kartu tidak ditemukan'], 404);
        DB::transaction(function () use ($uid, $r, $c) {
            DB::table('cards')->where('uid', $uid)->update([
                'name' => $r->json('name') ?? $c->name,
                'role' => $r->json('role') ?? $c->role,
                'active' => $r->json('active') === null ? $c->active : (bool) $r->json('active'),
                'all_devices' => $r->json('allDevices') === null ? $c->all_devices : (bool) $r->json('allDevices'),
            ]);
            $this->saveAccess($uid, $r);
            SmartDoor::bumpWhitelist();
        });
        return response()->json($this->row(DB::table('cards')->where('uid', $uid)->first()));
    }

    public function destroy(string $uid): JsonResponse
    {
        $uid = SmartDoor::normUid($uid);
        DB::transaction(function () use ($uid) {
            DB::table('card_access')->where('uid', $uid)->delete();
            DB::table('cards')->where('uid', $uid)->delete();
            SmartDoor::bumpWhitelist();
        });
        return response()->json(['ok' => true]);
    }
}
