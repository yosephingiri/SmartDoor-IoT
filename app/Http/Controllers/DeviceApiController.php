<?php

namespace App\Http\Controllers;

use App\Services\SmartDoor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * API untuk ESP32 (header X-Device-Key). Format sama dengan firmware smart_door_esp32.
 *   POST /api/v1/telemetry         snapshot seluruh state sensor & aktuator (objek atau array)
 *   POST /api/v1/events            event akses/alarm/pintu/sistem (objek atau array)
 *   GET  /api/v1/commands          polling perintah (±1 detik)
 *   POST /api/v1/commands/{id}/ack konfirmasi perintah
 *   GET  /api/v1/sync              whitelist kartu + konfigurasi
 */
class DeviceApiController extends Controller
{
    private function items(Request $r): array
    {
        $body = $r->json()->all();
        if (!$body) return [];
        return array_is_list($body) ? $body : [$body];
    }

    public function telemetry(Request $r): JsonResponse
    {
        $dev = $r->attributes->get('device');
        $list = $this->items($r);
        $last = null;
        DB::transaction(function () use ($dev, $list, &$last) {
            foreach ($list as $s) {
                if (!is_array($s)) continue;
                $ts = SmartDoor::storeTelemetry($dev, $s);
                if (!$last || $ts >= $last['ts']) $last = array_merge($s, ['ts' => $ts]);
            }
        });
        if ($last) {
            DB::table('devices')->where('id', $dev->id)->update([
                'state' => json_encode($last),
                'fw_version' => isset($last['fw']) ? mb_substr((string) $last['fw'], 0, 40) : $dev->fw_version,
            ]);
        }
        SmartDoor::maybePrune();
        return response()->json(SmartDoor::deviceReply($dev, ['ok' => true, 'stored' => count($list)]));
    }

    public function events(Request $r): JsonResponse
    {
        $dev = $r->attributes->get('device');
        $stored = 0;
        DB::transaction(function () use ($dev, $r, &$stored) {
            foreach ($this->items($r) as $e) {
                if (is_array($e) && SmartDoor::storeEvent($dev, $e)) $stored++;
            }
        });
        return response()->json(SmartDoor::deviceReply($dev, ['ok' => true, 'stored' => $stored]));
    }

    public function commands(Request $r): JsonResponse
    {
        return response()->json(SmartDoor::deviceReply($r->attributes->get('device')));
    }

    public function ack(Request $r, int $id): JsonResponse
    {
        $dev = $r->attributes->get('device');
        $n = DB::table('device_commands')->where('id', $id)->where('device_id', $dev->id)->update([
            'status' => $r->json('ok') === false ? 'failed' : 'done',
            'acked_at' => SmartDoor::now(),
            'result' => $r->json('detail') ? mb_substr((string) $r->json('detail'), 0, 200) : null,
        ]);
        return response()->json(['ok' => (bool) $n], $n ? 200 : 404);
    }

    public function sync(Request $r): JsonResponse
    {
        $dev = $r->attributes->get('device');
        return response()->json([
            'syncVersion' => SmartDoor::syncVersion($dev),
            'config' => array_merge(SmartDoor::defaults(), json_decode($dev->config ?: '{}', true) ?: []),
            'cards' => SmartDoor::cardsForDevice($dev->id),
            'serverTime' => SmartDoor::now(),
        ]);
    }
}
