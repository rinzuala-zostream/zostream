<?php

return [
    /*
    | OTP requests are limited per normalized recipient phone number, not by
    | the caller IP. Browser, TV, and mobile clients may legitimately share a
    | proxy or carrier NAT address, so an IP-only limit blocks unrelated users.
    */
    'request_max_attempts' => env('OTP_REQUEST_MAX_ATTEMPTS', 10),
    'admin_request_max_attempts' => env('ADMIN_OTP_REQUEST_MAX_ATTEMPTS', 6),
    'account_deletion_max_attempts' => env('ACCOUNT_DELETION_OTP_MAX_ATTEMPTS', 6),
    'verify_max_attempts' => env('OTP_VERIFY_MAX_ATTEMPTS', 10),
];
