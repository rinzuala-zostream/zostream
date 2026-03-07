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
        'http://localhost:8000',
        'http://localhost:3000',
        'http://192.168.137.160:8000'
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

