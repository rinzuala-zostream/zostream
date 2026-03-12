<?php

namespace App\Http\Controllers;

use App\Models\QrAuthSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QrAuthSessionController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_id' => 'nullable|string|max:255',
            'device_name' => 'nullable|string|max:150',
            'device_type' => 'nullable|string|in:mobile,browser,tv',
            'ttl_minutes' => 'nullable|integer|min:1|max:15',
        ]);

        $ttlMinutes = (int) ($validated['ttl_minutes'] ?? 5);
        $session = QrAuthSession::create([
            'session_token' => hash('sha256', Str::random(64)),
            'channel_code' => strtoupper(Str::random(8)),
            'device_id' => $validated['device_id'] ?? null,
            'device_name' => $validated['device_name'] ?? 'Unknown Device',
            'device_type' => $validated['device_type'] ?? 'browser',
            'status' => QrAuthSession::STATUS_PENDING,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        $mobileBaseUrl = rtrim(
            env('QR_AUTH_MOBILE_URL', env('APP_URL', config('app.url'))),
            '/'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'QR auth session created successfully',
            'data' => [
                'id' => $session->id,
                'session_token' => $session->session_token,
                'channel_code' => $session->channel_code,
                'device_id' => $session->device_id,
                'device_name' => $session->device_name,
                'device_type' => $session->device_type,
                'status' => $session->status,
                'expires_at' => optional($session->expires_at)->toDateTimeString(),
                'qr_url' => "{$mobileBaseUrl}/qr-login?token={$session->session_token}",
            ],
        ], 201);
    }

    public function show(string $token)
    {
        $session = $this->findByToken($token);

        if ($session->isExpired() && !$session->isCompleted()) {
            $session->markExpired();
            $session->refresh();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $session->id,
                'session_token' => $session->session_token,
                'channel_code' => $session->channel_code,
                'device_id' => $session->device_id,
                'device_name' => $session->device_name,
                'device_type' => $session->device_type,
                'status' => $session->status,
                'user_id' => $session->user_id,
                'auth_method' => $session->auth_method,
                'expires_at' => optional($session->expires_at)->toDateTimeString(),
                'approved_at' => optional($session->approved_at)->toDateTimeString(),
                'completed_at' => optional($session->completed_at)->toDateTimeString(),
            ],
        ]);
    }

    public function approve(Request $request, string $token)
    {
        $validated = $request->validate([
            'user_id' => 'required|string|max:225',
            'auth_method' => 'required|string|in:otp,password',
        ]);

        $session = $this->findByToken($token);

        if ($session->isExpired()) {
            $session->markExpired();
            return response()->json([
                'status' => 'error',
                'message' => 'QR auth session has expired',
            ], 410);
        }

        if ($session->isCompleted()) {
            return response()->json([
                'status' => 'error',
                'message' => 'QR auth session is already completed',
            ], 409);
        }

        if ($session->status === QrAuthSession::STATUS_CANCELLED) {
            return response()->json([
                'status' => 'error',
                'message' => 'QR auth session has been cancelled',
            ], 409);
        }

        $session->markApproved(
            $validated['user_id'],
            $validated['auth_method']
        );

        return response()->json([
            'status' => 'success',
            'message' => 'QR auth session approved successfully',
            'data' => [
                'session_token' => $session->session_token,
                'status' => QrAuthSession::STATUS_APPROVED,
                'user_id' => $validated['user_id'],
                'auth_method' => $validated['auth_method'],
                'approved_at' => now()->toDateTimeString(),
            ],
        ]);
    }

    public function complete(string $token, TokenController $tokenController)
    {
        $session = $this->findByToken($token);

        if ($session->isExpired()) {
            $session->markExpired();
            return response()->json([
                'status' => 'error',
                'message' => 'QR auth session has expired',
            ], 410);
        }

        if (!$session->isApproved()) {
            return response()->json([
                'status' => 'error',
                'message' => 'QR auth session is not approved yet',
            ], 409);
        }

        $tokens = $tokenController->generateTokens(
            $session->user_id,
            $session->device_name,
            $session->device_id
        );

        $session->markCompleted();

        return response()->json([
            'status' => 'success',
            'message' => 'QR auth session completed successfully',
            'data' => [
                'uid' => $session->user_id,
                'auth_method' => $session->auth_method,
                'session_token' => $session->session_token,
                'completed_at' => now()->toDateTimeString(),
                'tokens' => $tokens,
            ],
        ]);
    }

    public function cancel(string $token)
    {
        $session = $this->findByToken($token);

        if ($session->isCompleted()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Completed QR auth sessions cannot be cancelled',
            ], 409);
        }

        $session->markCancelled();

        return response()->json([
            'status' => 'success',
            'message' => 'QR auth session cancelled successfully',
            'data' => [
                'session_token' => $session->session_token,
                'status' => QrAuthSession::STATUS_CANCELLED,
            ],
        ]);
    }

    protected function findByToken(string $token): QrAuthSession
    {
        $session = QrAuthSession::where('session_token', $token)->first();

        if (!$session) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'QR auth session not found',
            ], 404));
        }

        return $session;
    }
}
