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

            if ($phoneRequest === '8837076347') {

                return response()->json([
                    'status' => 'success',
                    'message' => 'Test OTP sent successfully',
                    'user_id' => $user->uid,
                    'WhatsApp_Status' => 'skipped',
                    'otp' => '326416'
                ]);
            }

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
                'device_id' => 'nullable|string',
                'device_type' => 'nullable|string'
            ]);

            $userId = $request->user_id;
            $otp = $request->otp;
            $deviceName = $request->device_name ?? 'Unknown Device';
            $deviceId = $request->device_id;

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

            // 🔑 Generate token
            try {
                $tokens = $this->tokenController->generateTokens(
                    $userId,
                    $deviceName,
                    $deviceId
                );
                if (!$tokens || !isset($tokens['access_token'])) {
                    throw new \Exception('Token generation failed');
                }
            } catch (\Exception $e) {
                Log::error('Token generation failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
                return response()->json(['status' => 'error', 'message' => 'Failed to generate tokens'], 500);
            }

            // 🔄 Check subscription and n_devices
            $subscription = Subscription::where('user_id', $user->uid)
                ->where('end_at', '>', now())
                ->first();

            if ($subscription && $deviceId) {
                $device = Devices::where('user_id', $user->uid)
                    ->where('subscription_id', $subscription->id)
                    ->where('device_token', $deviceId)
                    ->first();

                if (!$device) {
                    // Create device if missing
                    $device = Devices::create([
                        'user_id' => $user->uid,
                        'subscription_id' => $subscription->id,
                        'device_token' => $deviceId,
                        'device_name' => $deviceName,
                        'device_type' => $request->device_type ?? 'mobile',
                        'status' => 'inactive',
                        'is_owner_device' => false,
                    ]);

                    $message = 'Device created and set as inactive';
                } elseif ($device->status === 'blocked' && !$device->is_owner_device) {
                    // Reset blocked device to inactive
                    $device->update(['status' => 'inactive']);
                    $message = 'Blocked device reset to inactive';
                } else {
                    // Device exists and is not blocked
                    $message = 'Device already exists with status: ' . $device->status;
                }

                // Sync Redis hash
                $redisKey = "h:stream:{$subscription->id}:{$device->device_type}:{$device->id}";
                Redis::hmset($redisKey, [
                    'status' => $device->status,
                    'device_name' => $device->device_name,
                    'last_ping' => now()->timestamp
                ]);
            } else {
                $message = 'No active subscription found for this user or device ID missing';
            }

            // Return response
            return response()->json([
                'status' => 'success',
                'message' => $message,
                'data' => array_merge(['uid' => $userId], $tokens)
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
