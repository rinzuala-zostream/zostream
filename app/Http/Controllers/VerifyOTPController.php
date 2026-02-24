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
            // 🔒 Validate API key
            $apiKey = $request->header('X-Api-Key');
            if ($apiKey !== $this->validApiKey) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid API key'
                ], 401);
            }

            // 🧾 Validate input
            $request->validate([
                'user_id' => 'required|string',
                'otp' => 'required|string',
                'device_name' => 'nullable|string',
                'device_id' => 'nullable|string'
            ]);

            $userId = $request->user_id;
            $otp = $request->otp;

            // 🔍 Get latest OTP request
            $otpRequest = OTPRequestModel::where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$otpRequest) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No OTP request found for this user'
                ], 404);
            }

            // ⏰ Check OTP expiration
            if (strtotime($otpRequest->expires_at) < time()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'OTP expired'
                ], 400);
            }

            // ❌ Verify OTP hash
            if (!Hash::check($otp, $otpRequest->otp_code)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid OTP'
                ], 400);
            }

            // ✅ OTP is correct → delete record
            $otpRequest->delete();

            // 🔎 Get user
            $user = UserModel::where('uid', $userId)->first();
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // 🔑 Attempt token generation
            try {
                $tokens = $this->tokenController->generateTokens(
                    $userId,
                    $request->device_name,
                    $request->device_id
                );

                if (!$tokens || !isset($tokens['access_token'])) {
                    throw new Exception('Token generation failed');
                }
            } catch (Exception $e) {
                Log::error('Token generation failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage()
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to generate tokens. Please try again.'
                ], 500);
            }

            // 🎉 Success response
            return response()->json([
                'status' => 'success',
                'message' => 'OTP verified successfully',
                'data' => array_merge(['uid' => $userId], $tokens)
            ], 200);

        } catch (Exception $e) {
            // 🧨 Catch unexpected errors
            Log::error('OTP verification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred. Please try again later.'
            ], 500);
        }
    }
}