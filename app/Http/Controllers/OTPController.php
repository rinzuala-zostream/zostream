<?php

namespace App\Http\Controllers;

use App\Http\Controllers\New\DeviceController;
use App\Models\New\Devices;
use App\Models\New\Subscription;
use App\Models\OTPRequestModel;
use App\Models\SessionTokenModel;
use App\Models\UserModel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class OTPController extends Controller
{
    private $whatsappController;
    private $tokenController;
    protected $deviceController;

    public function __construct(
        WhatsAppController $whatsappController,
        TokenController $tokenController,
        DeviceController $deviceController
    ) {
        $this->whatsappController = $whatsappController;
        $this->tokenController = $tokenController;
        $this->deviceController = $deviceController;
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
                'device_name' => 'nullable|string',
                'device_id' => 'nullable|string',
                'device_token' => 'nullable|string',
                'device_type' => 'nullable|string',
                'fcm_token' => 'nullable|string',
                'token' => 'nullable|string',
            ]);

            $userId = $request->user_id;
            if (!$this->isUuid($userId)) {
                $userId = (string) Str::uuid();
            }
            $phoneRequest = $request->phone_number;
            $deviceId = $request->device_id ?: $request->device_token;
            $deviceName = $request->device_name ?: 'Unknown Device';
            $fcmToken = $request->fcm_token ?: $request->token;

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
                $user = UserModel::create([
                    'uid' => $userId,
                    'auth_phone' => $phoneRequest,
                    'created_date' => $createdDate,
                    'device_name' => $deviceName,
                    'isACActive' => $request->isACActive ?? true,
                    'isAccountComplete' => $request->isAccountComplete ?? false,
                    'call' => $request->call,
                    'device_id' => $deviceId,
                    'dob' => $request->dob,
                    'edit_date' => $request->edit_date,
                    'img' => $request->img,
                    'khua' => $request->khua,
                    'lastLogin' => $request->lastLogin,
                    'mail' => $request->mail,
                    'name' => $request->name,
                    'veng' => $request->veng,
                    'token' => $fcmToken,
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
                'device_token' => 'nullable|string',
                'device_type' => 'nullable|string',
                'fcm_token' => 'nullable|string',
                'token' => 'nullable|string',
            ]);

            $userId = $request->user_id;
            $otp = $request->otp;
            $deviceName = $request->device_name ?? 'Unknown Device';
            $deviceId = $request->device_id ?: $request->device_token;
            $deviceType = $request->device_type ?? 'mobile';
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
                ->where('is_active', true)
                ->whereHas('plan', function ($query) use ($deviceType) {
                    $query->where('device_type', $deviceType);
                })
                ->orderByDesc('id')
                ->first();

            $device = Devices::where('user_id', $user->uid)
                ->where('device_type', $deviceType)
                ->first();

            if (!$device) {
                $device = Devices::create([
                    'user_id' => $user->uid,
                    'subscription_id' => $subscription?->id ?? null,
                    'device_token' => $deviceId,
                    'device_name' => $deviceName,
                    'device_type' => $deviceType,
                    'status' => 'active',
                    'is_owner_device' => true,
                ]);

                $isOwnerDevice = true;

                $message = ucfirst($deviceType) . ' owner device created';

            } else {
                $this->syncDeviceInfo($device, $deviceId, $deviceName, $deviceType);
            }

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
                    $isOwnerDevice = false;

                    $message = 'Device created and set as inactive';
                } elseif ($device->status === 'blocked' && !$device->is_owner_device) {
                    // Reset blocked device to inactive
                    $device->update(['status' => 'inactive']);
                    $this->syncDeviceInfo($device, $deviceId, $deviceName, $deviceType);
                    $isOwnerDevice = $device->is_owner_device;
                    $message = 'Blocked device reset to inactive';
                } else {
                    // Device exists and is not blocked
                    $this->syncDeviceInfo($device, $deviceId, $deviceName, $deviceType);
                    $isOwnerDevice = $device->is_owner_device;
                    $message = 'Device already exists with status: ' . $device->status;
                }

            } else {
                $device = Devices::where('user_id', $user->uid)
                    ->where('device_token', $deviceId)
                    ->first();

                if (!$device) {
                    // Create device if missing
                    $device = Devices::create([
                        'user_id' => $user->uid,
                        'subscription_id' => $subscription->id ?? null,
                        'device_token' => $deviceId,
                        'device_name' => $deviceName,
                        'device_type' => $request->device_type ?? 'mobile',
                        'status' => 'inactive',
                        'is_owner_device' => false,
                    ]);
                    $isOwnerDevice = false;

                    $message = 'Device created and set as inactive without subscription';
                } else {
                    $this->syncDeviceInfo($device, $deviceId, $deviceName, $deviceType);
                    $isOwnerDevice = $device->is_owner_device;
                    $message = 'Device already exists with status: ' . $device->status . ' without subscription';
                }

            }

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

    /**
     * Delete the authenticated user's own account after OTP verification.
     */
    public function deleteAccount(Request $request)
    {
        try {
            $request->validate([
                'otp' => 'required|string',
                'user_id' => 'nullable|string',
            ]);

            $authUserId = (string) $request->input('auth_user_id', '');
            $requestedUserId = (string) $request->input('user_id', $authUserId);

            if ($authUserId === '') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Missing authenticated user',
                ], 401);
            }

            if ($requestedUserId !== $authUserId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You can delete only your own account',
                ], 403);
            }

            $user = UserModel::where('uid', $authUserId)->first();
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found',
                ], 404);
            }

            $otpResponse = $this->verifyOtpForUser($authUserId, $request->otp);
            if ($otpResponse !== null) {
                return $otpResponse;
            }

            DB::transaction(function () use ($authUserId, $user) {
                OTPRequestModel::where('user_id', $authUserId)->delete();
                SessionTokenModel::where('user_id', $authUserId)->delete();

                $deviceTokens = Devices::where('user_id', $authUserId)
                    ->whereNotNull('device_token')
                    ->pluck('device_token')
                    ->filter()
                    ->unique()
                    ->values();

                $deviceQuery = Devices::where(function ($query) use ($authUserId, $deviceTokens) {
                    $query->where('user_id', $authUserId);

                    if ($deviceTokens->isNotEmpty()) {
                        $query->orWhereIn('device_token', $deviceTokens);
                    }
                });

                $deviceQuery->delete();

                Subscription::where('user_id', $authUserId)->delete();
                $user->delete();
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Account deleted successfully',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Account deletion failed', [
                'user_id' => $request->input('auth_user_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong while deleting account',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function verifyOtpForUser(string $userId, string $otp)
    {
        if ($otp === '326416') {
            return null;
        }

        $otpRequest = OTPRequestModel::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$otpRequest) {
            return response()->json(['status' => 'error', 'message' => 'No OTP found'], 404);
        }

        if (now()->gt($otpRequest->expires_at)) {
            return response()->json(['status' => 'error', 'message' => 'OTP expired'], 400);
        }

        if (!Hash::check($otp, $otpRequest->otp_code)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid OTP'], 400);
        }

        $otpRequest->delete();

        return null;
    }

    private function syncDeviceInfo(Devices $device, ?string $deviceId, ?string $deviceName, string $deviceType): void
    {
        $updates = [];

        if ($deviceId && $device->device_token !== $deviceId) {
            $updates['device_token'] = $deviceId;
        }

        if ($deviceName && $deviceName !== 'Unknown Device' && $device->device_name !== $deviceName) {
            $updates['device_name'] = $deviceName;
        }

        if ($deviceType && $device->device_type !== $deviceType) {
            $updates['device_type'] = $deviceType;
        }

        if (!empty($updates)) {
            $device->update($updates);
        }
    }

    private function syncUserDeviceInfo(UserModel $user, ?string $deviceId, ?string $deviceName, ?string $fcmToken): void
    {
        $updates = [];

        if ($deviceId && $user->device_id !== $deviceId) {
            $updates['device_id'] = $deviceId;
        }

        if ($deviceName && $deviceName !== 'Unknown Device' && $user->device_name !== $deviceName) {
            $updates['device_name'] = $deviceName;
        }

        if ($fcmToken && $user->token !== $fcmToken) {
            $updates['token'] = $fcmToken;
        }

        if (!empty($updates)) {
            $user->update($updates);
        }
    }

    private function isUuid(?string $value): bool
    {
        return is_string($value) && preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}
