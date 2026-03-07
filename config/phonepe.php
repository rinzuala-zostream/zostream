<?php
return [
    'env' => env('PHONEPE_ENV', 'production'), // 'sandbox' | 'production'

    // Production
    'client_id'     => env('PHONEPE_CLIENT_ID', ''),
    'client_secret' => env('PHONEPE_CLIENT_SECRET', ''),

    // Sandbox
    'sandbox_client_id'     => env('PHONEPE_SANDBOX_CLIENT_ID', ''),
    'sandbox_client_secret' => env('PHONEPE_SANDBOX_CLIENT_SECRET', ''),

    // Networking
    'timeout' => (int) env('PHONEPE_TIMEOUT', 15),
];
