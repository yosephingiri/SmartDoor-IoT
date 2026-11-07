<?php

return [
    // Zona waktu laporan dalam menit dari UTC (WIB = 420)
    'tz_offset_min' => (int) env('SMARTDOOR_TZ_OFFSET_MIN', 420),

    // Perangkat dianggap offline jika tidak menghubungi server selama ini (ms)
    'online_timeout_ms' => (int) env('SMARTDOOR_ONLINE_TIMEOUT_MS', 15000),

    // Perintah remote yang tidak diambil perangkat dalam waktu ini dibatalkan (ms)
    'command_ttl_ms' => (int) env('SMARTDOOR_COMMAND_TTL_MS', 30000),

    // Lama penyimpanan telemetri (hari)
    'retention_days' => (int) env('SMARTDOOR_RETENTION_DAYS', 180),

    // Parameter perilaku pintu default (bisa diubah per perangkat dari dashboard)
    'default_device_config' => [
        'unlockMs' => 5000,          // relay ON maks. 5 detik bila pintu tidak dibuka
        'leftOpenAlarmS' => 30,      // alarm jika pintu terbuka > 30 detik
        'maxFail' => 3,              // kartu ditolak 3x berturut-turut -> alarm
        'blockS' => 30,              // reader diblokir 30 detik
        'telemetryIntervalS' => 10,  // telemetri periodik (selain saat state berubah)
    ],
];
