<?php

return [
    'stream_timeout_seconds' => (int) env('PLAYBACK_STREAM_TIMEOUT_SECONDS', 500),

    'write_intervals' => [
        'heartbeat_seconds' => (int) env('PLAYBACK_HEARTBEAT_WRITE_INTERVAL', 45),
        'device_activity_seconds' => (int) env('PLAYBACK_DEVICE_ACTIVITY_WRITE_INTERVAL', 300),
    ],

    'rate_limits' => [
        'start_per_minute' => (int) env('PLAYBACK_START_RATE_LIMIT', 60),
        'heartbeat_per_minute' => (int) env('PLAYBACK_HEARTBEAT_RATE_LIMIT', 20),
        'stop_per_minute' => (int) env('PLAYBACK_STOP_RATE_LIMIT', 120),
    ],
];
