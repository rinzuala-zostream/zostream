<?php

namespace App\Support;

use App\Models\New\AdminUser;
use App\Models\SessionTokenModel;
use App\Models\UserModel;
use Carbon\Carbon;
use Illuminate\Http\Request;

final class AdminAccess
{
    public function authenticatedAdminId(Request $request): ?string
    {
        $authorization = (string) $request->header('Authorization', '');

        if (! str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorization, 7));
        $session = $token !== '' ? SessionTokenModel::findByAccessToken($token) : null;

        if (! $session || Carbon::parse($session->access_expires_at)->isPast()) {
            return null;
        }

        $userId = trim((string) $session->user_id);

        return $userId !== '' && $this->isAdmin($userId) ? $userId : null;
    }

    public function isAdmin(string $userId): bool
    {
        if (AdminUser::where('admin_uid', $userId)->exists()) {
            return true;
        }

        if (in_array($userId, config('services.admin_qr.allowed_uids', []), true)) {
            return true;
        }

        $user = UserModel::where('uid', $userId)->first();
        $phone = preg_replace('/\D+/', '', (string) ($user?->auth_phone ?? ''));

        if ($phone === '') {
            return false;
        }

        foreach (config('services.admin_whatsapp.allowed_numbers', []) as $allowed) {
            $allowed = preg_replace('/\D+/', '', (string) $allowed);

            if ($allowed !== '' && (
                $allowed === $phone
                || substr($allowed, -10) === substr($phone, -10)
            )) {
                return true;
            }
        }

        return false;
    }
}
