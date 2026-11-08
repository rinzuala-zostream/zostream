<?php

namespace App\Support;

use App\Models\SessionTokenModel;
use Carbon\Carbon;
use Illuminate\Http\Request;

final class MizoOnlyContent
{
    public const USER_ID = 'AW7ovVnTdgWuvE1Uke7QTQ5OEQt1';

    public static function appliesTo(string $userId): bool
    {
        return trim($userId) === self::USER_ID;
    }

    /** Resolve trusted authentication before considering client-supplied IDs. */
    public static function userId(Request $request): string
    {
        $authenticatedUserId = trim((string) $request->input('auth_user_id', ''));
        if ($authenticatedUserId !== '') {
            return $authenticatedUserId;
        }

        $authorization = (string) $request->header('Authorization', '');
        if (str_starts_with($authorization, 'Bearer ')) {
            $token = trim(substr($authorization, 7));
            $session = $token !== '' ? SessionTokenModel::findByAccessToken($token) : null;

            if ($session && ! Carbon::parse($session->access_expires_at)->isPast()) {
                return trim((string) $session->user_id);
            }
        }

        $headerUserId = trim((string) $request->header('X-User-Id', ''));
        if ($headerUserId !== '') {
            return $headerUserId;
        }

        return trim((string) $request->query('user_id', ''));
    }
}
