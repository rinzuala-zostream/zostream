<?php

namespace App\Http\Controllers;

use App\Models\OTPRequestModel;
use App\Models\UserModel;
use Hash;
use Illuminate\Http\Request;
use \Twilio\Rest\Client;


class RequestOTPController extends Controller
{
    private $validApiKey;

    private $twilioSid;
    private $twilioToken;
    private $twilioFrom = "Zo Stream";

    public function __construct()
    {
        $this->validApiKey = config('app.api_key');
        $this->twilioSid = config('app.twilio_id');
        $this->twilioToken = config('app.twilio_token');
    }

    public function sendOTP(Request $request)
    {

       
        $apiKey = $request->header('api_key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
        }

        $request->validate([
            'user_id' => 'required|string',
            'phone_number' => 'required|string'
        ]);

        $userId = $request->user_id;
        $phone = $request->phone_number;

        // Check if user exists
        $user = UserModel::where('uid', $userId)->first();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
        }

        $otp = rand(100000, 999999);
        $otpHash = Hash::make($otp);
        $expiry = now()->addMinutes(2);

        // Check if unverified OTP exists
        $existingOtp = OTPRequestModel::where('user_id', $userId)
            ->where('is_verified', 0)
            ->first();

        if ($existingOtp) {
            $otpRequest = OTPRequestModel::where('id', $existingOtp->id)
                ->update([
                    'otp_code' => $otpHash,
                    'expires_at' => $expiry
                ]);
        } else {
            $otpRequest = OTPRequestModel::create([
                'user_id' => $userId,
                'otp_code' => $otpHash,
                'expires_at' => $expiry,
                'is_verified' => 0,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        $smsStatus = $this->sendSMS($phone, "Zo Stream share account OTP chu: $otp");

        return response()->json([
            'status' => 'success',
            'message' => 'OTP sent successfully',
            'SMS_Status' => $smsStatus,
            'otp' => app()->environment('local') ? $otp : null  // Show OTP only in local/dev environment
        ]);
    }

    private function sendSMS($to, $message)
    {
        try {
            $twilio = new Client($this->twilioSid, $this->twilioToken);
            $twilio->messages->create($to, [
                'from' => $this->twilioFrom,
                'body' => $message
            ]);
            return 'Sent';
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage();
        }
    }
}
