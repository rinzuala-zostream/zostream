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

class OTPController extends Controller
{
    private $whatsappController;
    private $tokenController;

    public function __construct(WhatsAppController $whatsappController, TokenController $tokenController)
    {
        $this->whatsappController = $whatsappController;
        $this->tokenController = $tokenController;
    }

    public function send(Request $request)
    {
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
                'device_name' => $request->device_name ?? 'Unknown Device',
                'is_auth_phone_active' => true
            ]);
        }

        $otp = rand(100000, 999999);
        $otpHash = Hash::make($otp);

        OTPRequestModel::updateOrCreate(
            ['user_id' => $user->uid, 'is_verified' => 0],
            ['otp_code' => $otpHash, 'expires_at' => now()->addMinutes(5)]
        );

        try {
            $payload = [
                "to" => $phoneRequest,
                "type" => "template",
                "template_name" => "zostream_auth_otp",
                "template_params" => [$otp],
                "language" => "en"
            ];
            $this->whatsappController->send(new Request($payload));
        } catch (Exception $e) {
            Log::warning("WhatsApp OTP send failed: " . $e->getMessage());
        }

        return response()->json([
            'status' => 'success',
            'message' => 'OTP sent successfully',
            'user_id' => $user->uid,
            'otp' => app()->environment('local') ? $otp : null
        ]);
    }

    public function verify(Request $request)
    {
        $request->validate([
            'user_id' => 'required|string',
            'otp' => 'required|string',
            'device_name' => 'nullable|string',
            'device_id' => 'nullable|string',
            'device_type' => 'nullable|string'
        ]);

        $userId = $request->user_id;
        $otp = $request->otp;
        $deviceId = $request->device_id;
        $deviceName = $request->device_name ?? 'Unknown Device';
        $deviceType = $request->device_type ?? 'mobile';

        $otpRequest = OTPRequestModel::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$otpRequest || now()->gt($otpRequest->expires_at)) {
            return response()->json(['status' => 'error', 'message' => 'OTP expired or not found'], 400);
        }

        if (!Hash::check($otp, $otpRequest->otp_code)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid OTP'], 400);
        }

        $otpRequest->delete();

        $user = UserModel::where('uid', $userId)->first();
        if (!$user) return response()->json(['status' => 'error', 'message' => 'User not found'], 404);

        $tokens = $this->tokenController->generateTokens($userId, $deviceName, $deviceId);

        // Check subscription
        $subscription = Subscription::where('user_id', $userId)
            ->where('end_at', '>', now())
            ->first();

        if ($subscription) {
            $device = Devices::firstOrCreate(
                ['user_id' => $userId, 'subscription_id' => $subscription->id, 'device_token' => $deviceId],
                ['device_name' => $deviceName, 'device_type' => $deviceType, 'status' => 'inactive', 'is_owner_device' => false]
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => array_merge(['uid' => $userId], $tokens),
            'message' => 'OTP verified and device registered'
        ]);
    }
}