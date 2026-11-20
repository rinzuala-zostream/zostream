<?php

namespace App\Http\Middleware;

use App\Models\SessionTokenModel;
use App\Support\Api\V4Response;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdleSessionMiddleware
{
    public const MAX_IDLE_SECONDS = 60 * 60 * 24 * 7;

    public function handle(Request $request, Closure $next): Response
    {
        $userId = (string) $request->input('auth_user_id', '');
        $sessionId = $request->attributes->get('auth_session_token_id');
        $session = $sessionId
            ? SessionTokenModel::whereKey($sessionId)->where('user_id', $userId)->first()
            : null;

        if (! $session) {
            return V4Response::error(
                'ADMIN_SESSION_INVALID',
                'Administrator session is no longer valid. Please sign in again.',
                401
            );
        }

        if ($this->isExpired($session)) {
            $session->delete();

            return V4Response::error(
                'ADMIN_SESSION_EXPIRED',
                'Administrator session expired due to inactivity. Please sign in again.',
                401
            );
        }

        $this->recordActivity($session);

        return $next($request);
    }

    public function isExpired(SessionTokenModel $session): bool
    {
        $lastActivity = $session->updated_at ?? $session->created_at;

        return ! $lastActivity || $lastActivity->lte(now()->subSeconds(self::MAX_IDLE_SECONDS));
    }

    public function recordActivity(SessionTokenModel $session): void
    {
        $session->forceFill(['updated_at' => now()])->save();
    }
}
