<?php

return [
    'stream_timeout_seconds' => (int) env('PLAYBACK_STREAM_TIMEOUT_SECONDS', 500),
    'inactive_stream_retention_hours' => (int) env('PLAYBACK_INACTIVE_STREAM_RETENTION_HOURS', 24),
    'inactive_stream_prune_batch' => (int) env('PLAYBACK_INACTIVE_STREAM_PRUNE_BATCH', 1000),

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
