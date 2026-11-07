<?php

namespace App\Http\Controllers;

use App\Services\SmartDoor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Manajemen node pintu (ESP32). */
class DeviceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(DB::table('devices')->orderBy('id')->get()->map(fn ($d) => SmartDoor::deviceRow($d)));
    }

    private function cleanConfig(array $in, array $base): array
    {
        foreach (array_keys(SmartDoor::defaults()) as $k) {
            if (isset($in[$k]) && is_numeric($in[$k]) && $in[$k] > 0) $base[$k] = (int) $in[$k];
        }
        $base['unlockMs'] = min($base['unlockMs'], 9000);   // solenoid tidak boleh aktif > 10 detik
        return $base;
    }

    public function store(Request $r): JsonResponse
    {
        $id = trim(preg_replace('/[^a-z0-9_-]/', '-', strtolower((string) $r->json('id'))), '-');
        if ($id === '' || !$r->json('name')) return response()->json(['error' => 'ID dan nama perangkat wajib diisi'], 400);
        if (DB::table('devices')->where('id', $id)->exists()) return response()->json(['error' => 'ID perangkat sudah dipakai'], 409);
        $key = Str::random(32);
        DB::table('devices')->insert([
            'id' => $id,
            'name' => (string) $r->json('name'),
            'location' => (string) ($r->json('location') ?? ''),
            'api_key' => $key,
            'config' => json_encode($this->cleanConfig((array) $r->json('config', []), SmartDoor::defaults())),
            'created_at' => SmartDoor::now(),
        ]);
        $row = SmartDoor::deviceRow(DB::table('devices')->where('id', $id)->first());
        return response()->json($row + ['apiKey' => $key], 201);
    }

    public function update(Request $r, string $id): JsonResponse
    {
        $d = DB::table('devices')->where('id', $id)->first();
        if (!$d) return response()->json(['error' => 'Perangkat tidak ditemukan'], 404);
        $old = array_merge(SmartDoor::defaults(), json_decode($d->config ?: '{}', true) ?: []);
        $new = $this->cleanConfig((array) $r->json('config', []), $old);
        DB::table('devices')->where('id', $id)->update([
            'name' => $r->json('name') ?? $d->name,
            'location' => $r->json('location') ?? $d->location,
            'config' => json_encode($new),
            'config_ver' => $d->config_ver + ($new != $old ? 1 : 0),
        ]);
        return response()->json(SmartDoor::deviceRow(DB::table('devices')->where('id', $id)->first()));
    }

    public function key(string $id): JsonResponse
    {
        $k = DB::table('devices')->where('id', $id)->value('api_key');
        return $k ? response()->json(['apiKey' => $k]) : response()->json(['error' => 'Perangkat tidak ditemukan'], 404);
    }

    public function rotateKey(string $id): JsonResponse
    {
        $key = Str::random(32);
        $n = DB::table('devices')->where('id', $id)->update(['api_key' => $key]);
        return $n ? response()->json(['apiKey' => $key]) : response()->json(['error' => 'Perangkat tidak ditemukan'], 404);
    }

    public function destroy(string $id): JsonResponse
    {
        DB::table('card_access')->where('device_id', $id)->delete();
        DB::table('devices')->where('id', $id)->delete();   // log historis tetap disimpan
        return response()->json(['ok' => true]);
    }
}
