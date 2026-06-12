<?php

namespace App\Support;

use App\Models\UserModel;

class WhatsAppPhone
{
    public static function digitsOnly($value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?: '';
    }

    public static function countryCode(?UserModel $user = null, array $fallback = []): string
    {
        $candidates = [
            $user?->country_code,
            $fallback['country_code'] ?? null,
            $user?->call,
            $fallback['call'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $digits = self::digitsOnly($candidate);
            if ($digits !== '' && strlen($digits) <= 4) {
                return $digits;
            }
        }

        return '';
    }

    public static function recipient(?UserModel $user = null, ?string $fallbackPhone = null, array $fallback = []): string
    {
        $phone = self::digitsOnly($user?->auth_phone) ?: self::digitsOnly($fallbackPhone);
        if ($phone === '') {
            return '';
        }

        $countryCode = self::countryCode($user, $fallback);
        if ($countryCode === '') {
            return $phone;
        }

        if (str_starts_with($phone, $countryCode) && strlen($phone) > strlen($countryCode) + 6) {
            return $phone;
        }

        return $countryCode . $phone;
    }

    public static function lookupCandidates(string $phone, array $countryCodes = []): array
    {
        $phone = self::digitsOnly($phone);
        if ($phone === '') {
            return [];
        }

        $candidates = [$phone];

        foreach ($countryCodes as $countryCode) {
            $countryCode = self::digitsOnly($countryCode);
            if ($countryCode === '') {
                continue;
            }

            if (str_starts_with($phone, $countryCode)) {
                $localPhone = substr($phone, strlen($countryCode));
                if ($localPhone !== '') {
                    $candidates[] = $localPhone;
                }
            } else {
                $candidates[] = $countryCode . $phone;
            }
        }

        if (strlen($phone) > 10) {
            $candidates[] = substr($phone, -10);
        }

        return array_values(array_unique(array_filter($candidates)));
    }
}
