<?php

namespace App\Http\Controllers;

use App\Models\OTPRequestModel;
use App\Models\UserModel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class RequestOTPController extends Controller
{
    private $validApiKey;
    private $whatsappController;

    public function __construct(WhatsAppController $whatsappController)
    {
        $this->whatsappController = $whatsappController;
        $this->validApiKey = config('app.api_key');
    }

    /**
     * Send OTP to existing user (user_id required)
     */
    public function sendOTP(Request $request)
    {
        try {
            // ✅ API key check
            $apiKey = $request->header('X-Api-Key');
            if ($apiKey !== $this->validApiKey) {
                return response()->json(["status" => "error", "message" => "Invalid API key"]);
            }

            // Validate input
            $request->validate([
                'user_id' => 'nullable|string',
                'phone_number' => 'nullable|string', // optional override
            ]);

            $userId = $request->user_id;
            $phoneRequest = $this->normalizeComparablePhone($request->phone_number);

            // 🔍 Find user
            $user = UserModel::where('uid', $userId)->first();

            // ✅ If user not found, create a new one
            if (!$user) {
                if (!$phoneRequest) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'User not found and no phone provided to create new user'
                    ]);
                }

                $createdDate = $request->created_date ?: Carbon::now()->format('M d, Y h:i:s a'); // e.g., "Feb 19, 2026 11:45:10 am"
                $deviceName = $request->device_name ?: 'Unknown Device'; // default device name

                $user = UserModel::create([
                    'uid' => $request->user_id,
                    'auth_phone' => $phoneRequest,
                    'created_date' => $createdDate,
                    'device_name' => $deviceName,
                    'isACActive' => $request->isACActive ?? true,
                    'isAccountComplete' => $request->isAccountComplete ?? false,
                    // Optional fields
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

            // Determine OTP target phone
            $otpPhone = $this->normalizeWhatsappTargetPhone($user->auth_phone ?? $phoneRequest);
            if (!$otpPhone) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No phone available to send OTP'
                ]);
            }

            // 🔐 Generate OTP
            $otp = rand(100000, 999999);
            $otpHash = Hash::make($otp);
            $expiry = now()->addMinutes(5);

            // 🔁 Update or create OTP request
            $existingOtp = OTPRequestModel::where('user_id', $user->uid)
                ->where('is_verified', 0)
                ->first();

            if ($existingOtp) {
                $existingOtp->update([
                    'otp_code' => $otpHash,
                    'expires_at' => $expiry
                ]);
            } else {
                OTPRequestModel::create([
                    'user_id' => $user->uid,
                    'otp_code' => $otpHash,
                    'expires_at' => $expiry,
                    'is_verified' => 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // 📞 Send OTP via WhatsApp
            $whatsappStatus = null;
            if ($otpPhone) {
                $payload = [
                    "to" => $otpPhone,
                    "type" => "template",
                    "template_name" => "zostream_auth_otp",
                    "template_params" => [$otp],
                    "language" => "en"
                ];
                $response = $this->whatsappController->send(new Request($payload));
                $whatsappStatus = $response->getStatusCode() === 200 ? 'sent' : 'failed';
            }

            return response()->json([
                'status' => 'success',
                'message' => 'OTP sent successfully',
                'user_id' => $user->uid,
                'WhatsApp_Status' => $whatsappStatus,
                'otp' => app()->environment('local') ? $otp : null
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ]);
        } catch (\Exception $e) {
            Log::error('OTP send failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong while sending OTP',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ]);
        }
    }

    private function normalizeComparablePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return '';
        }

        return substr($digits, -10);
    }

    private function normalizeWhatsappTargetPhone(?string $phone): string
    {
        $comparable = $this->normalizeComparablePhone($phone);

        return $comparable === '' ? '' : '91' . $comparable;
    }
}
