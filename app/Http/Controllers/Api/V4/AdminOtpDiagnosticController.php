<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Http\Controllers\OTPController;
use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Restricted support-only OTP delivery diagnostic.
 *
 * The generated code is intentionally returned only from this controller.
 * Do not add the reveal attribute to any public OTP route.
 */
class AdminOtpDiagnosticController extends Controller
{
    public function __construct(private readonly OTPController $otpController) {}

    public function sendAndReveal(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'string', 'max:100'],
            'country_code' => ['required', 'string', 'max:10', 'regex:/^\+?\d{1,4}$/'],
            'phone_number' => ['required', 'string', 'regex:/^\d{3,15}$/'],
        ]);

        $user = UserModel::where('uid', $validated['user_id'])->first();
        if (! $user) {
            return response()->json(['message' => 'User ID was not found.'], 404);
        }

        $requestedPhone = $this->fullPhone($validated['phone_number'], $validated['country_code']);
        $registeredPhone = $this->fullPhone((string) $user->auth_phone, (string) $user->country_code);

        // A support user may view an OTP only for the number already registered
        // to this UID. This avoids this diagnostic being used to provision or
        // redirect accounts to arbitrary numbers.
        if ($requestedPhone === '' || $registeredPhone === '' || $requestedPhone !== $registeredPhone) {
            return response()->json([
                'message' => 'The country code and phone number must match this user’s registered WhatsApp number.',
            ], 422);
        }

        $request->merge([
            'user_id' => (string) $user->uid,
            'country_code' => (string) $user->country_code,
            'phone_number' => (string) $user->auth_phone,
        ]);
        $request->attributes->set('allow_otp_reveal', true);

        $response = $this->otpController->send($request);
        $payload = $response->getData(true);
        $deliveryStatus = (string) ($payload['WhatsApp_Status'] ?? 'unknown');

        Log::notice('Admin OTP diagnostic requested.', [
            'admin_user_id' => (string) $request->input('auth_user_id'),
            'target_user_id' => (string) $user->uid,
            'phone_last4' => substr($registeredPhone, -4),
            'delivery_status' => $deliveryStatus,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'status' => $deliveryStatus === 'sent' ? 'success' : 'error',
            'message' => $deliveryStatus === 'sent'
                ? 'OTP sent and revealed for support verification.'
                : 'OTP was created, but WhatsApp did not accept the delivery request.',
            'user_id' => (string) $user->uid,
            'delivery_status' => $deliveryStatus,
            'otp' => $payload['otp'] ?? null,
        ], $deliveryStatus === 'sent' ? 200 : 502);
    }

    private function fullPhone(string $phone, string $countryCode): string
    {
        $phone = preg_replace('/\D+/', '', $phone) ?: '';
        $countryCode = preg_replace('/\D+/', '', $countryCode) ?: '';

        if ($phone === '') {
            return '';
        }

        return $countryCode === '' || str_starts_with($phone, $countryCode)
            ? $phone
            : $countryCode.$phone;
    }
}
