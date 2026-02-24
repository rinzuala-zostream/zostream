<?php

namespace App\Http\Controllers;

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

    /**
     * 📤 Send OTP (create user if not exists)
     */
    public function send(Request $request)
    {
        try {

            // 🧾 Validate input
            $request->validate([
                'user_id' => 'nullable|string',
                'phone_number' => 'required|string',
            ]);

            $userId = $request->user_id;
            $phoneRequest = $request->phone_number;

            // 🔍 Find user
            $user = UserModel::where('auth_phone', $phoneRequest)->first();

            // ✅ Create user if not found
            if (!$user) {
                if (!$phoneRequest) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'User not found and no phone provided'
                    ]);
                }

                $createdDate = $request->created_date ?: Carbon::now()->format('M d, Y h:i:s a');
                $deviceName = $request->device_name ?: 'Unknown Device';

                $user = UserModel::create([
                    'uid' => $userId,
                    'auth_phone' => $phoneRequest,
                    'created_date' => $createdDate,
                    'device_name' => $deviceName,
                    'isACActive' => $request->isACActive ?? true,
                    'isAccountComplete' => $request->isAccountComplete ?? false,
                    'call' => $request->call,
                    'device_id' => $request->device_id,
                    'dob' => $request->dob,
                    'edit_date' => $request->edit_date,
                    'img' => $request->img,
                    'khua' => $request->khua,
                    'lastLogin' => $request->lastLogin,
                    'mail' => $request->mail,
                    'name' => $request->name,
                    'veng' => $request->veng,
                    'token' => $request->token,
                    'is_auth_phone_active' => true,
                ]);
            }

            // 📱 Determine OTP target phone
            $otpPhone = $user->auth_phone ?? $phoneRequest;
            if (!$otpPhone) {
                return response()->json(['status' => 'error', 'message' => 'No phone available to send OTP']);
            }

            // 🔢 Generate OTP
            $otp = rand(100000, 999999);
            $otpHash = Hash::make($otp);
            $expiry = now()->addMinutes(5);

            // 🔁 Store OTP
            OTPRequestModel::updateOrCreate(
                ['user_id' => $user->uid, 'is_verified' => 0],
                ['otp_code' => $otpHash, 'expires_at' => $expiry, 'updated_at' => now()]
            );

            // 📞 Send OTP via WhatsApp
            $whatsappStatus = null;
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
                Log::warning('WhatsApp OTP send failed', ['error' => $e->getMessage()]);
                $whatsappStatus = 'failed';
            }

            return response()->json([
                'status' => 'success',
                'message' => 'OTP sent successfully',
                'user_id' => $user->uid,
                'WhatsApp_Status' => $whatsappStatus,
                'otp' => app()->environment('local') ? $otp : null,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ]);
        } catch (Exception $e) {
            Log::error('OTP send failed', ['error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Something went wrong']);
        }
    }

    /**
     * ✅ Verify OTP and generate token
     */
    public function verify(Request $request)
    {
        try {

            // 🧾 Validate request
            $request->validate([
                'user_id' => 'required|string',
                'otp' => 'required|string',
                'device_name' => 'nullable|string',
                'device_id' => 'nullable|string'
            ]);

            $userId = $request->user_id;
            $otp = $request->otp;

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

            // 🔎 Find user
            $user = UserModel::where('uid', $userId)->first();
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
            }

            // 🔑 Generate token
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
                Log::error('Token generation failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
                return response()->json(['status' => 'error', 'message' => 'Failed to generate tokens'], 500);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'OTP verified successfully',
                'data' => array_merge(['uid' => $userId], $tokens)
            ]);

        } catch (Exception $e) {
            Log::error('OTP verification failed', ['error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Unexpected error']);
        }
    }
}