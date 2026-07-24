<?php

namespace App\Http\Middleware;

use App\Models\New\AdminUser;
use App\Models\UserModel;
use App\Support\Api\V4Response;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = (string) $request->input('auth_user_id', '');

        if ($userId === '' || ! $this->isAdmin($userId)) {
            return V4Response::error(
                'ADMIN_ACCESS_REQUIRED',
                'Administrator access is required.',
                403
            );
        }

        return $next($request);
    }

    private function isAdmin(string $userId): bool
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
