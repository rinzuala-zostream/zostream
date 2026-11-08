<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Http\Controllers\OTPController;
use App\Http\Controllers\TokenController;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly OTPController $otp,
        private readonly TokenController $tokens,
    ) {}

    public function requestOtp(Request $request)
    {
        return $this->otp->send($request);
    }

    public function verifyOtp(Request $request)
    {
        return $this->otp->verify($request);
    }

    public function refresh(Request $request)
    {
        return $this->tokens->refresh($request);
    }

    public function logout(Request $request)
    {
        $bearer = $request->bearerToken();
        $request->merge([
            'access_token' => $bearer,
            'user_id' => $request->input('auth_user_id'),
        ]);

        return $this->tokens->revoke($request);
    }
}
