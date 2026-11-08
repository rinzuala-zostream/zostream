<?php

return [
    'upload_disk' => env('ADS_UPLOAD_DISK', 'public'),
    'upload_directory' => env('ADS_UPLOAD_DIRECTORY', 'ads/submissions'),
    'default_country_code' => env('ADS_DEFAULT_COUNTRY_CODE', '91'),
    'payment_whatsapp_template' => env('ADS_PAYMENT_WHATSAPP_TEMPLATE'),
    'payment_whatsapp_template_language' => env('ADS_PAYMENT_WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
];
