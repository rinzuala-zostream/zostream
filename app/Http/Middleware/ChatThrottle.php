<?php

namespace App\Http\Middleware;

use App\Support\Api\V4Response;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class ChatThrottle
{
    public function handle(Request $request, Closure $next, int $maxAttempts = 60)
    {
        $key = 'chat:http:'.hash('sha256', $request->input('auth_user_id').':'.$request->route()->getActionName());
        try {
            $limiter = new RateLimiter(Cache::store(config('chat.cache_store')));
            $limited = $limiter->tooManyAttempts($key, $maxAttempts);
            if (! $limited) {
                $limiter->hit($key, 60);
            }
        } catch (\Throwable $error) {
            // History remains readable during Redis outages without creating
            // database-backed throttle writes for every connected viewer.
            if (! $request->isMethod('GET')) {
                return V4Response::error('CHAT_UNAVAILABLE', 'Chat is temporarily unavailable. Please retry.', 503);
            }
            $limiter = new RateLimiter(Cache::store('file'));
            $limited = $limiter->tooManyAttempts($key, $maxAttempts);
            if (! $limited) {
                $limiter->hit($key, 60);
            }
        }
        if ($limited) {
            return V4Response::error('CHAT_RATE_LIMITED', 'Please wait before trying again.', 429)
                ->header('Retry-After', $limiter->availableIn($key));
        }

        return $next($request);
    }
}
