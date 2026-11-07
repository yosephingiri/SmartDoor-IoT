<?php

namespace App\Http\Middleware;

use App\Services\SmartDoor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Autentikasi ESP32 memakai header X-Device-Key (API key unik per perangkat). */
class DeviceAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('X-Device-Key', '');
        $dev = $key !== '' ? DB::table('devices')->where('api_key', $key)->first() : null;
        if (!$dev) {
            return response()->json(['error' => 'X-Device-Key tidak valid'], 401);
        }
        SmartDoor::touch($dev, $request->ip());
        $request->attributes->set('device', $dev);
        return $next($request);
    }
}
