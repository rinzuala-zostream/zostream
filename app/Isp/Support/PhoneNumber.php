<?php

namespace App\Isp\Support;

class PhoneNumber
{
    public static function normalize(?string $value): ?string
    {
        $value = trim($value ?? '');
        if ($value === '' || ! preg_match('/^\+?[0-9 ()-]+$/', $value)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value);
        if (strlen($digits) === 10) {
            $digits = '91'.$digits;
        }

        return preg_match('/^[1-9][0-9]{10,14}$/', $digits) ? $digits : null;
    }
}
