<?php

use App\Http\Controllers\DeviceApiController;
use Illuminate\Support\Facades\Route;

// API untuk ESP32. Prefix "/api" otomatis dari bootstrap/app.php -> /api/v1/...
Route::prefix('v1')->middleware('device')->group(function () {
    Route::post('/telemetry', [DeviceApiController::class, 'telemetry']);
    Route::post('/events', [DeviceApiController::class, 'events']);
    Route::get('/commands', [DeviceApiController::class, 'commands']);
    Route::post('/commands/{id}/ack', [DeviceApiController::class, 'ack'])->whereNumber('id');
    Route::get('/sync', [DeviceApiController::class, 'sync']);
});
