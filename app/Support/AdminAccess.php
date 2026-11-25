<?php

namespace App\Support;

use App\Models\New\AdminUser;
use App\Models\SessionTokenModel;
use App\Models\UserModel;
use Carbon\Carbon;
use Illuminate\Http\Request;

final class AdminAccess
{
    public function authenticatedAdminId(Request $request, bool $whitelistOnly = false): ?string
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

        if ($userId === '') {
            return null;
        }

        $allowed = $whitelistOnly ? $this->isWhitelistedAdmin($userId) : $this->isAdmin($userId);

        return $allowed ? $userId : null;
    }

    public function isAdmin(string $userId): bool
    {
        if (AdminUser::where('admin_uid', $userId)->exists()) {
            return true;
        }

        return $this->isWhitelistedAdmin($userId);
    }

    /** @return list<string> User IDs that satisfy the configured admin rules. */
    public function adminIds(iterable $userIds): array
    {
        $ids = collect($userIds)->filter(fn ($id) => $id !== null && (string) $id !== '')
            ->map(fn ($id) => (string) $id)->unique()->values()->all();
        if ($ids === []) { return []; }

        $admins = AdminUser::whereIn('admin_uid', $ids)->pluck('admin_uid')->map(fn ($id) => (string) $id)->all();
        $allowedUids = array_intersect($ids, config('services.admin_qr.allowed_uids', []));
        $admins = array_merge($admins, $allowedUids);

        $allowedPhones = array_values(array_filter(array_map(
            fn ($number) => preg_replace('/\D+/', '', (string) $number),
            config('services.admin_whatsapp.allowed_numbers', [])
        )));
        if ($allowedPhones !== []) {
            foreach (UserModel::whereIn('uid', $ids)->get(['uid', 'auth_phone']) as $user) {
                $phone = preg_replace('/\D+/', '', (string) ($user->auth_phone ?? ''));
                if ($phone !== '' && collect($allowedPhones)->contains(fn ($allowed) => $allowed !== '' && (
                    $allowed === $phone || substr($allowed, -10) === substr($phone, -10)
                ))) {
                    $admins[] = (string) $user->uid;
                }
            }
        }

        return array_values(array_unique($admins));
    }

    public function isWhitelistedAdmin(string $userId): bool
    {
        if ($userId === '') {
            return false;
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
