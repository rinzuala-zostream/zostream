<?php

namespace App\Support\Chat;

final class InvitationPhone
{
    public static function international(?string $number, ?string $countryCode): ?string
    {
        $raw = trim($number ?? '');
        $phone = preg_replace('/\D+/', '', $raw);
        $country = ltrim(preg_replace('/\D+/', '', $countryCode ?? ''), '0');
        $international = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        if (str_starts_with($raw, '00')) { $phone = substr($phone, 2); }
        if ($phone === '') { return null; }
        if (! $international) {
            // Match invitee lookup's explicit fallback for legacy Indian accounts.
            if ($country === '' && strlen($phone) === 10) { $country = '91'; }
            if ($country !== '') {
                if (! (str_starts_with($phone, $country) && strlen($phone) > 10)) {
                    $phone = $country.ltrim($phone, '0');
                }
            } elseif (strlen($phone) <= 10) { return null; }
        }
        return strlen($phone) >= 8 && strlen($phone) <= 15 && ! str_starts_with($phone, '0') ? $phone : null;
    }
}
