<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\PanelController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [PanelController::class, 'dashboard']);

    // Endpoint JSON yang dipakai dashboard (public/smartdoor/app.js)
    Route::prefix('panel')->group(function () {
        Route::get('/me', [PanelController::class, 'me']);
        Route::get('/overview', [PanelController::class, 'overview']);
        Route::get('/live', [PanelController::class, 'live']);
        Route::get('/presence', [PanelController::class, 'presence']);
        Route::get('/events', [PanelController::class, 'events']);
        Route::get('/events-csv', [PanelController::class, 'eventsCsv']);
        Route::get('/telemetry', [PanelController::class, 'telemetry']);
        Route::get('/commands', [PanelController::class, 'commands']);
        Route::get('/analytics', AnalyticsController::class);

        Route::get('/devices', [DeviceController::class, 'index']);
        Route::post('/devices', [DeviceController::class, 'store']);
        Route::patch('/devices/{id}', [DeviceController::class, 'update']);
        Route::delete('/devices/{id}', [DeviceController::class, 'destroy']);
        Route::get('/devices/{id}/key', [DeviceController::class, 'key']);
        Route::post('/devices/{id}/rotate-key', [DeviceController::class, 'rotateKey']);
        Route::post('/devices/{id}/commands', [PanelController::class, 'sendCommand']);

        Route::get('/cards', [CardController::class, 'index']);
        Route::get('/cards/unknown', [CardController::class, 'unknown']);
        Route::post('/cards', [CardController::class, 'store']);
        Route::patch('/cards/{uid}', [CardController::class, 'update']);
        Route::delete('/cards/{uid}', [CardController::class, 'destroy']);
    });
});
