<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'admin_qr' => [
        'allowed_uids' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('ADMIN_QR_ALLOWED_UIDS', ''))
        ))),
    ],

    'admin_whatsapp' => [
        'allowed_numbers' => array_values(array_filter(array_map(
            static fn ($number) => preg_replace('/\D+/', '', trim($number)),
            explode(',', env('ADMIN_WHATSAPP_ALLOWED_NUMBERS', ''))
        ))),
    ],

    'whatsapp' => [
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v22.0'),
    ],

    'amazon_iap' => [
        'shared_secret' => env('AMAZON_IAP_SHARED_SECRET'),
        'sandbox' => env('AMAZON_IAP_SANDBOX', false),
        'parent_sku' => env('AMAZON_IAP_PARENT_SKU', 'zostream_sub'),
        'term_skus' => [
            'week' => env('AMAZON_IAP_WEEK_SKU', 'zostream.week'),
            'month' => env('AMAZON_IAP_MONTH_SKU', 'zostream.month'),
            'four_months' => env('AMAZON_IAP_FOUR_MONTHS_SKU', 'zostream.4months'),
            'six_months' => env('AMAZON_IAP_SIX_MONTHS_SKU', 'zostream.6months'),
            'year' => env('AMAZON_IAP_YEAR_SKU', 'zostream.year'),
        ],
    ],

];
