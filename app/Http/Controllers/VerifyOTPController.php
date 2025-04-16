<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OTPRequestModel;
use Illuminate\Support\Facades\Hash;

class VerifyOTPController extends Controller
{

    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key');
        
    }

    public function verify(Request $request)
    {

        
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => "Invalid API key"]);
        }

        $request->validate([
            'user_id' => 'required|string',
            'otp' => 'required|string'
        ]);

        $userId = $request->user_id;
        $otp = $request->otp;

        // Get the latest OTP request
        $otpRequest = OTPRequestModel::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$otpRequest) {
            return response()->json(['status' => 'error', 'message' => 'No OTP request found']);
        }

        // Check if OTP is expired
        if (strtotime($otpRequest->expires_at) < time()) {
            return response()->json(['status' => 'error', 'message' => 'OTP expired']);
        }

        // Verify the OTP
        if (!Hash::check($otp, $otpRequest->otp_code)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid OTP']);
        }

        // Delete the OTP after successful verification
        $otpRequest->delete();

        return response()->json(['status' => 'success', 'message' => 'OTP verified successfully']);
    }
}
