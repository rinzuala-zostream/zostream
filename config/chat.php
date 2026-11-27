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
    'page_size' => 20,
    'recent_limit' => 100,
    'slow_mode_seconds' => 2,
    'private_member_limit' => 10,
    'watch_member_limit' => 5,
    'socket_protocol_enabled' => env('CHAT_SOCKET_PROTOCOL_ENABLED', true),
    'websocket_url' => env('CHAT_WEBSOCKET_URL', ''),
    'firebase' => [
        // Deliberately disabled until supported clients and the authorization
        // projections have passed the phased rollout checks.
        'enabled' => env('CHAT_FIREBASE_ENABLED', false),
        // Separate final gate; backend projection alone never grants client reads.
        'client_access_enabled' => env('CHAT_FIREBASE_CLIENT_ACCESS_ENABLED', false),
        'account_events_enabled' => env('CHAT_FIREBASE_ACCOUNT_EVENTS_ENABLED', true),
        // Dedicated stable key. Do not rotate without an identity migration.
        'uid_key' => env('CHAT_FIREBASE_UID_KEY', ''),
        'session_lease_seconds' => 300,
        'authorization_lease_seconds' => 240,
    ],
];
