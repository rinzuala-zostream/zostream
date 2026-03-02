<?php

namespace App\Http\Controllers;

use App\Models\New\Devices;
use App\Models\New\Subscription;
use App\Models\OTPRequestModel;
use App\Models\UserModel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Support\Facades\Redis;

class OTPController extends Controller
{
    private $whatsappController;
    private $tokenController;

    // 🔥 TEST CONFIG (WORKS IN PRODUCTION)
    private $testPhone = '8837076347';
    private $testOtp   = '326416';

    public function __construct(WhatsAppController $whatsappController, TokenController $tokenController)
    {
        $this->whatsappController = $whatsappController;
        $this->tokenController = $tokenController;
    }

    /**
     * 📤 Send OTP
     */
    public function send(Request $request)
    {
        try {

            $request->validate([
                'user_id' => 'nullable|string',
                'phone_number' => 'required|string',
            ]);

            $userId = $request->user_id;
            $phoneRequest = $request->phone_number;

            $user = UserModel::where('auth_phone', $phoneRequest)->first();

            if (!$user) {

                $user = UserModel::create([
                    'uid' => $userId,
                    'auth_phone' => $phoneRequest,
                    'created_date' => Carbon::now()->format('M d, Y h:i:s a'),
                    'device_name' => $request->device_name ?? 'Unknown Device',
                    'isACActive' => true,
                    'isAccountComplete' => false,
                    'is_auth_phone_active' => true,
                ]);
            }

            $otpPhone = $user->auth_phone;

            // 🔥 Production Test OTP
            if ($otpPhone === $this->testPhone) {
                $otp = $this->testOtp;
                $whatsappStatus = 'skipped_test_mode';
            } else {
                $otp = rand(100000, 999999);
                $whatsappStatus = 'pending';
            }

            $otpHash = Hash::make($otp);
            $expiry = now()->addMinutes(5);

            OTPRequestModel::updateOrCreate(
                ['user_id' => $user->uid, 'is_verified' => 0],
                [
                    'otp_code' => $otpHash,
                    'expires_at' => $expiry,
                    'updated_at' => now()
                ]
            );

            // 📞 Send WhatsApp (skip for test)
            if ($otpPhone !== $this->testPhone) {
                try {
                    $payload = [
                        "to" => $otpPhone,
                        "type" => "template",
                        "template_name" => "zostream_auth_otp",
                        "template_params" => [$otp],
                        "language" => "en"
                    ];

                    $response = $this->whatsappController->send(new Request($payload));
                    $whatsappStatus = $response->getStatusCode() === 200 ? 'sent' : 'failed';

                } catch (Exception $e) {
                    Log::warning('WhatsApp OTP failed', ['error' => $e->getMessage()]);
                    $whatsappStatus = 'failed';
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'OTP sent successfully',
                'user_id' => $user->uid,
                'WhatsApp_Status' => $whatsappStatus,
            ]);

        } catch (Exception $e) {
            Log::error('OTP send failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong'
            ]);
        }
    }

    /**
     * ✅ Verify OTP
     */
    public function verify(Request $request)
    {
        try {

            $request->validate([
                'user_id' => 'required|string',
                'otp' => 'required|string',
            ]);

            $userId = $request->user_id;
            $otp = $request->otp;

            $otpRequest = OTPRequestModel::where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$otpRequest) {
                return response()->json(['status' => 'error', 'message' => 'No OTP found'], 404);
            }

            if (now()->gt($otpRequest->expires_at)) {
                return response()->json(['status' => 'error', 'message' => 'OTP expired'], 400);
            }

            $user = UserModel::where('uid', $userId)->first();

            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
            }

            // 🔥 Production Test OTP Bypass
            if ($user->auth_phone === $this->testPhone && $otp === $this->testOtp) {
                // allow
            } elseif (!Hash::check($otp, $otpRequest->otp_code)) {
                return response()->json(['status' => 'error', 'message' => 'Invalid OTP'], 400);
            }

            $otpRequest->delete();

            $tokens = $this->tokenController->generateTokens(
                $userId,
                $request->device_name ?? 'Unknown Device',
                $request->device_id
            );

            return response()->json([
                'status' => 'success',
                'message' => 'OTP verified successfully',
                'data' => array_merge(['uid' => $userId], $tokens)
            ]);

        } catch (Exception $e) {
            Log::error('OTP verification failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Verification failed'
            ]);
        }
    }
}