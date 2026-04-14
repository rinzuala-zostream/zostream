<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://play.zostream.in',
        'https://admin.zostream.in',
        'https://preview.zostream.in',
        'https://quiz.buannelstudio.in',
        'https://zostream.in',
        'https://tv.zostream.in',
        'http://localhost:5173',
        'http://127.0.0.1:8000',
        'http://localhost:8000',
        'http://localhost:3000',
        'http://192.168.137.160:8000',
        'https://web.zostream.in',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

