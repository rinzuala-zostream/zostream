<?php

return [
    'stream_timeout_seconds' => (int) env('PLAYBACK_STREAM_TIMEOUT_SECONDS', 500),

    'rate_limits' => [
        'start_per_minute' => (int) env('PLAYBACK_START_RATE_LIMIT', 60),
        'heartbeat_per_minute' => (int) env('PLAYBACK_HEARTBEAT_RATE_LIMIT', 300),
        'stop_per_minute' => (int) env('PLAYBACK_STOP_RATE_LIMIT', 120),
    ],
];
