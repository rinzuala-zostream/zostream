<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SessionTokenModel;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TokenController extends Controller
{
    /**
     * Generate random access & refresh tokens for a user
     */
    public function generateTokens($userId, $deviceName = null, $deviceId = null)
    {
        // Random tokens
        $accessToken = hash('sha256', Str::random(60));
        $refreshToken = hash('sha256', Str::random(60));

        // Expiry times
        $accessExp = Carbon::now()->addHours(1);   // 1 hour
        $refreshExp = Carbon::now()->addDays(30);  // 30 days

        // Save to DB
        SessionTokenModel::create([
            'user_id' => $userId,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'access_expires_at' => $accessExp,
            'refresh_expires_at' => $refreshExp,
            'device_name' => $deviceName,
            'device_id' => $deviceId,
        ]);

        return [
            'access_token' => $accessToken,
            'access_expires_at' => $accessExp->toDateTimeString(),
            'refresh_token' => $refreshToken,
            'refresh_expires_at' => $refreshExp->toDateTimeString(),
            'token_type' => 'bearer',
            'device_name' => $deviceName,
            'device_id' => $deviceId,
        ];
    }

    /**
     * Refresh access token using refresh token
     */
    public function refresh(Request $request)
    {
        $request->validate([
            'refresh_token' => 'required|string'
        ]);

        $refreshToken = $request->refresh_token;

        $record = SessionTokenModel::where('refresh_token', $refreshToken)->first();

        if (!$record) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid refresh token'
            ], 401);
        }

        // Refresh token expired
        if ($record->refresh_expires_at->isPast()) {
            $record->delete();

            return response()->json([
                'status' => 'error',
                'message' => 'Refresh token expired'
            ], 401);
        }

        // Generate new tokens
        $newAccessToken = hash('sha256', Str::random(60));
        $newRefreshToken = hash('sha256', Str::random(60));

        $newAccessExp = Carbon::now()->addHours(1);
        $newRefreshExp = Carbon::now()->addDays(30);

        $record->update([
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'access_expires_at' => $newAccessExp,
            'refresh_expires_at' => $newRefreshExp,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Access token refreshed successfully',
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'access_expires_at' => $newAccessExp->toDateTimeString(),
            'refresh_expires_at' => $newRefreshExp->toDateTimeString(),
            'token_type' => 'bearer'
        ]);
    }

    /**
     * Validate Access Token for API requests
     */
    public static function validateToken($token)
    {
        $record = SessionTokenModel::where('access_token', $token)->first();

        if (!$record)
            return null;
        if ($record->access_expires_at->isPast())
            return null;

        return $record->user_id;
    }

    /**
     * Logout / revoke tokens
     */
    public function revoke(Request $request)
    {
        $request->validate(['access_token' => 'required|string']);

        SessionTokenModel::where('access_token', $request->access_token)->delete();

        return response()->json(['status' => 'success', 'message' => 'Logged out successfully']);
    }
}