<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://play.zostream.in',
        'https://admin.zostream.in',
        'https://preview.zostream.in',
        'https://tv.zostream.in',
        'http://localhost:3000',
        'https://web.zostream.in',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

