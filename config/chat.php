<?php

return [
    'enabled' => env('CHAT_ENABLED', false),
    'cache_store' => env('CHAT_CACHE_STORE', 'redis'),
    'retention_days' => 30,
    'page_size' => 50,
    'recent_limit' => 100,
    'slow_mode_seconds' => 2,
    'websocket_url' => env('CHAT_WEBSOCKET_URL', ''),
];
