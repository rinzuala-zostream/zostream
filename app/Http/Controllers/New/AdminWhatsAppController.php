<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Http\Controllers\OTPController;
use App\Http\Controllers\WhatsAppController;
use App\Models\UserModel;
use Illuminate\Http\Request;

class AdminWhatsAppController extends Controller
{
    public function __construct(
        private WhatsAppController $whatsAppController,
        private OTPController $otpController,
    ) {
    }

    public function requestOtp(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => 'required|string',
            'user_id' => 'nullable|string',
        ]);

        $normalizedPhone = $this->normalizePhone($validated['phone_number']);
        if (!$this->isAllowedPhone($normalizedPhone)) {
            return response()->json([
                'status' => 'error',
                'message' => 'This phone number is not allowed to access admin login.',
            ], 403);
        }

        $request->merge([
            'phone_number' => $normalizedPhone,
        ]);

        return $this->otpController->send($request);
    }

    public function send(Request $request)
    {
        $validated = $request->validate([
            'to' => 'required|string',
            'type' => 'required|string|in:template,text',
            'template_name' => 'nullable|string',
            'template_params' => 'nullable|array',
            'language' => 'nullable|string',
            'message' => 'nullable|string',
        ]);

        $authUserId = (string) $request->input('auth_user_id', '');
        if ($authUserId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing authenticated user.',
            ], 401);
        }

        $user = UserModel::where('uid', $authUserId)->first();
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Authenticated user not found.',
            ], 404);
        }

        $authPhone = $this->normalizePhone((string) ($user->auth_phone ?? ''));
        if (!$this->isAllowedPhone($authPhone)) {
            return response()->json([
                'status' => 'error',
                'message' => 'This account is not allowed to use admin WhatsApp.',
            ], 403);
        }

        return $this->whatsAppController->send(new Request($validated));
    }

    private function getAllowedPhones()
    {
        return config('services.admin_whatsapp.allowed_numbers', []);
    }

    private function isAllowedPhone(string $phone)
    {
        if ($phone === '') {
            return false;
        }

        $normalizedPhone = $this->normalizePhone($phone);
        $phoneSuffix = substr($normalizedPhone, -10);

        foreach ($this->getAllowedPhones() as $allowedPhone) {
            $normalizedAllowedPhone = $this->normalizePhone((string) $allowedPhone);

            if ($normalizedAllowedPhone === '') {
                continue;
            }

            if (
                $normalizedAllowedPhone === $normalizedPhone
                || substr($normalizedAllowedPhone, -10) === $phoneSuffix
            ) {
                return true;
            }
        }

        return false;
    }

    private function normalizePhone(string $phone)
    {
        return preg_replace('/\D+/', '', trim($phone));
    }
}
