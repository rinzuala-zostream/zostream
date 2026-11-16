<?php

return [
    'max_source_bytes' => (int) env('CHANNEL_SOURCE_MAX_BYTES', 512 * 1024 * 1024),
    'max_artwork_bytes' => 10 * 1024 * 1024,
    // Below the common PHP 2 MB upload limit; no large request bodies required.
    'chunk_bytes' => 1024 * 1024,
    'session_hours' => 24,
    'max_pending_per_channel' => 3,
    'max_source_bytes_per_channel' => (int) env('CHANNEL_SOURCE_QUOTA_BYTES', 10 * 1024 * 1024 * 1024),
];
