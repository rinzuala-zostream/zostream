<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\SessionTokenModel;
use Carbon\Carbon;

class AuthTokenMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing or invalid Authorization header',
            ], 401);
        }

        $accessToken = trim(substr($authHeader, 7));
        $record = SessionTokenModel::where('access_token', $accessToken)->first();

        if (!$record) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or revoked token'], 401);
        }

        if (Carbon::parse($record->access_expires_at)->isPast()) {
            return response()->json(['status' => 'error', 'message' => 'Access token expired'], 401);
        }

        // Attach user_id to request
        $request->merge(['auth_user_id' => $record->user_id]);

        return $next($request);
    }
}