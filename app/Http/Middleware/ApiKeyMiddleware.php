<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = (string) config('app.api_key');
        $providedKey = (string) $request->header('X-Api-Key', '');

        if (
            $configuredKey === ''
            || $configuredKey === 'default_value'
            || $providedKey === ''
            || !hash_equals($configuredKey, $providedKey)
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid API key',
            ], 401);
        }

        return $next($request);
    }
}
