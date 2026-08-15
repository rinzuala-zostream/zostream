<?php

namespace App\Http\Middleware;

use App\Support\AdminAccess;
use App\Support\Api\V4Response;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminTokenMiddleware
{
    public function __construct(private readonly AdminAccess $adminAccess) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = (string) $request->input('auth_user_id', '');

        if ($userId === '' || ! $this->adminAccess->isAdmin($userId)) {
            return V4Response::error(
                'ADMIN_ACCESS_REQUIRED',
                'Administrator access is required.',
                403
            );
        }

        return $next($request);
    }

}
