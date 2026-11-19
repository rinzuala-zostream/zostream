<?php

return [
    // Enable tracking first, review a dry run, then enable delivery on the server.
    'tracking_enabled' => env('ENGAGEMENT_TRACKING_ENABLED', false),
    'enabled' => env('ENGAGEMENT_ENABLED', false),
    'timezone' => 'Asia/Kolkata',
    'queue_connection' => env('ENGAGEMENT_QUEUE_CONNECTION', 'database'),
    'queue' => 'engagement',
    'batch_size' => (int) env('ENGAGEMENT_BATCH_SIZE', 100),
    'pushes_per_minute' => (int) env('ENGAGEMENT_PUSHES_PER_MINUTE', 10),
    'user_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ENGAGEMENT_USER_ALLOWLIST', ''))))),
    'quiet_start_hour' => 21,
    'quiet_end_hour' => 9,
    'default_send_hour' => 18,
    'cooldown_hours' => 48,
    'ignored_cooldown_hours' => 168,
    'existing_push_gap_hours' => 6,
    'continue_after_hours' => 24,
    'watchlist_after_days' => 3,
    'subscriber_after_days' => 3,
    'comeback_days' => [7, 14, 30],
    'search_after_hours' => 12,
    'post_watch_after_hours' => 24,
    'candidate_max_age_days' => 30,
    'recommendation_cache_seconds' => 3600,
];
