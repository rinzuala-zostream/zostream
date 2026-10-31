<?php

namespace App\Http\Middleware;

use App\Support\AdminAccess;
use App\Support\Api\V4Response;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminConfigWhitelistMiddleware
{
    public function __construct(private readonly AdminAccess $adminAccess) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Resolve identity from the bearer session, never from submitted UID/phone fields.
        if ($this->adminAccess->authenticatedAdminId($request, whitelistOnly: true) === null) {
            return V4Response::error(
                'ADMIN_WHITELIST_REQUIRED',
                'Whitelisted administrator access is required to change app configuration.',
                403
            );
        }

        return $next($request);
    }
}
