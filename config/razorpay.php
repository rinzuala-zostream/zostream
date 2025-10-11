<?php

return [
    // SANDBOX | PRODUCTION
    'env' => env('RAZORPAY_ENV', 'SANDBOX'),

    'test' => [
        'key_id'     => env('RAZORPAY_TEST_KEY_ID'),
        'key_secret' => env('RAZORPAY_TEST_KEY_SECRET'),
    ],

    'live' => [
        'key_id'     => env('RAZORPAY_LIVE_KEY_ID'),
        'key_secret' => env('RAZORPAY_LIVE_KEY_SECRET'),
    ],

    // Razorpay uses the same API base for test & live (keys decide the mode).
    'base_url' => 'https://api.razorpay.com/v1',
];
