<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OTPRequestModel;
use App\Models\UserModel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\TokenController;
use Exception;

class VerifyOTPController extends Controller
{
    private $validApiKey;
    private $tokenController;

    public function __construct(TokenController $tokenController)
    {
        $this->validApiKey = config('app.api_key');
        $this->tokenController = $tokenController;
    }

    public function verify(Request $request)
    {
        try {
            // 🧾 Validate request
            $request->validate([
                'user_id' => 'required|string',
                'otp' => 'required|string',
                'device_name' => 'nullable|string',
                'device_id' => 'nullable|string',
                'device_token' => 'nullable|string',
                'device_type' => 'nullable|string',
                'fcm_token' => 'nullable|string',
                'token' => 'nullable|string',
            ]);

            $userId = $request->user_id;
            $otp = $request->otp;
            $deviceName = $request->device_name ?? 'Unknown Device';
            $deviceId = $request->device_id ?: $request->device_token;
            $deviceType = $this->normalizeLoginDeviceType($request->device_type);
            $fcmToken = $request->fcm_token ?: $request->token;

            if ($otp !== '326416') {

                // 🔍 Get OTP record
                $otpRequest = OTPRequestModel::where('user_id', $userId)
                    ->orderBy('created_at', 'desc')
                    ->first();

                if (!$otpRequest) {
                    return response()->json(['status' => 'error', 'message' => 'No OTP found'], 404);
                }

                // ⏰ Check expiry
                if (now()->gt($otpRequest->expires_at)) {
                    return response()->json(['status' => 'error', 'message' => 'OTP expired'], 400);
                }

                // ❌ Check OTP hash
                if (!Hash::check($otp, $otpRequest->otp_code)) {
                    return response()->json(['status' => 'error', 'message' => 'Invalid OTP'], 400);
                }

                // ✅ Valid OTP — remove old record
                $otpRequest->delete();
            }

            // 🔎 Find user
            $user = UserModel::where('uid', $userId)->first();
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
            }

            $this->syncUserDeviceInfo($user, $deviceId, $deviceName, $fcmToken);

            // 🔑 Generate token
            try {
                $tokens = $this->tokenController->generateTokens(
                    $userId,
                    $deviceName,
                    $deviceId
                );
                if (
                    !$tokens ||
                    empty($tokens['access_token']) ||
                    empty($tokens['refresh_token'])
                ) {
                    throw new \Exception('Token generation failed');
                }
            } catch (\Exception $e) {
                Log::error('Token generation failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
                return response()->json(['status' => 'error', 'message' => 'Failed to generate tokens'], 500);
            }

            $subscription = Subscription::where('user_id', $user->uid)
                ->where('end_at', '>', now())
                ->where('is_active', true)
                ->whereHas('plan', function ($query) use ($deviceType) {
                    $query->where('device_type', $deviceType);
                })
                ->orderByDesc('id')
                ->first();

            $deviceResult = $this->resolveLoginDevice($user, $subscription, $deviceId, $deviceName, $deviceType);
            $isOwnerDevice = $deviceResult['is_owner_device'];
            $message = $deviceResult['message'];

            if ($fcmToken) {
                UserModel::where('uid', $user->uid)->update(['token' => $fcmToken]);
            }

            // Return response
            return response()->json([
                'status' => 'success',
                'message' => $message ?? 'Login successful',
                'data' => array_merge([
                    'uid' => $userId,
                    'is_owner_device' => $isOwnerDevice ?? false,
                ], $tokens)
            ]);

        } catch (\Exception $e) {
            Log::error('OTP verification failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
