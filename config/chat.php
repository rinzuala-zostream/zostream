<?php

return [
    'primary_connection' => env('DB_CONNECTION', 'mysql'),
    'connection' => env('CHAT_DB_CONNECTION', 'chat'),
    'whatsapp_enabled' => env('CHAT_WHATSAPP_ENABLED', false),
    'whatsapp_template' => env('CHAT_WHATSAPP_TEMPLATE', 'zostream_room_invitation'),
    'whatsapp_language' => env('CHAT_WHATSAPP_LANGUAGE', 'en_US'),
    'invitation_url' => env('CHAT_INVITATION_URL', 'https://app.zostream.in/invite'),
    'ios_app_ids' => array_filter(explode(',', env('CHAT_IOS_APP_IDS', '5N48USNJ2K.com.buannel.pvt.ltd.zostream.Zo-Stream'))),
    'android_fingerprints' => array_filter(explode(',', env('CHAT_ANDROID_SHA256_FINGERPRINTS', ''))),
    'enabled' => env('CHAT_ENABLED', false),
    'cache_store' => env('CHAT_CACHE_STORE', 'redis'),
    'retention_days' => 30,
    'page_size' => 50,
    'recent_limit' => 100,
    'slow_mode_seconds' => 2,
    'private_member_limit' => 10,
    'watch_member_limit' => 5,
    'socket_protocol_enabled' => env('CHAT_SOCKET_PROTOCOL_ENABLED', true),
    'websocket_url' => env('CHAT_WEBSOCKET_URL', ''),
];
