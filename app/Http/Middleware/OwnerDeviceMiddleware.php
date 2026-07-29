<?php

namespace App\Http\Middleware;

use App\Models\New\Devices;
use Closure;
use Illuminate\Http\Request;

class OwnerDeviceMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $userId = trim((string) $request->input('auth_user_id', ''));
        $deviceToken = trim((string) $request->input('auth_device_id', ''));

        if ($userId === '' || $deviceToken === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'A verified owner device is required for this action.',
            ], 403);
        }

        $isOwnerDevice = Devices::where('user_id', $userId)
            ->where('device_token', $deviceToken)
            ->where('is_owner_device', true)
            ->where('status', '!=', 'blocked')
            ->exists();

        if (! $isOwnerDevice) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only the account owner device can perform this action.',
            ], 403);
        }

        return $next($request);
    }
}
