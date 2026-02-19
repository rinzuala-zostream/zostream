<?php

namespace App\Http\Controllers;

use App\Models\OTPRequestModel;
use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class RequestOTPController extends Controller
{
    private $validApiKey;
    private $whatsappController;

    public function __construct(WhatsAppController $whatsappController)
    {
        $this->whatsappController = $whatsappController;
        $this->validApiKey = config('app.api_key');
    }

    public function sendOTP(Request $request)
    {
        try {
            $apiKey = $request->header('X-Api-Key');

            if ($apiKey !== $this->validApiKey) {
                return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
            }

            $request->validate([
                'user_id' => 'required|string',
            ]);

            $userId = $request->user_id;

            // 🔍 Check if user exists
            $user = UserModel::where('uid', $userId)->first();
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
            }

            if ($user->auth_phone === null) {
                return response()->json(['status' => 'error', 'message' => 'User does not have an authenticated phone number'], 400);
            }

            $phone = $user->auth_phone;

            // 🔐 Generate OTP
            $otp = rand(100000, 999999);
            $otpHash = Hash::make($otp);
            $expiry = now()->addMinutes(5);

            // 🔁 Check if unverified OTP exists
            $existingOtp = OTPRequestModel::where('user_id', $userId)
                ->where('is_verified', 0)
                ->first();

            if ($existingOtp) {
                $existingOtp->update([
                    'otp_code' => $otpHash,
                    'expires_at' => $expiry
                ]);
            } else {
                OTPRequestModel::create([
                    'user_id' => $userId,
                    'otp_code' => $otpHash,
                    'expires_at' => $expiry,
                    'is_verified' => 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // 📞 Send via WhatsApp if phone exists
            if ($phone !== null) {
                $payload = [
                    "to" => $phone,
                    "type" => "template",
                    "template_name" => "zostream_auth_otp",
                    "template_params" => [$otp],
                    "language" => "en"
                ];

                $response = $this->whatsappController->send(new Request($payload));

                return response()->json([
                    'status' => 'success',
                    'message' => 'OTP sent successfully',
                    'WhatsApp_Status' => $response->getStatusCode() === 200 ? 'sent' : 'failed',
                    'otp' => app()->environment('local') ? $otp : null
                ]);
            }

            // 📞 Return OTP directly if no phone
            return response()->json([
                'status' => 'success',
                'message' => $otp
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            // Log for debugging
            Log::error('OTP send failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong while sending OTP',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }
}
