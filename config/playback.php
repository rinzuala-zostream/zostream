<?php

return [
    'stream_timeout_seconds' => (int) env('PLAYBACK_STREAM_TIMEOUT_SECONDS', 500),
    'inactive_stream_retention_hours' => (int) env('PLAYBACK_INACTIVE_STREAM_RETENTION_HOURS', 24),
    'inactive_stream_prune_batch' => (int) env('PLAYBACK_INACTIVE_STREAM_PRUNE_BATCH', 1000),

    'rate_limits' => [
        'start_per_minute' => (int) env('PLAYBACK_START_RATE_LIMIT', 60),
        'stop_per_minute' => (int) env('PLAYBACK_STOP_RATE_LIMIT', 120),
    ],
];
