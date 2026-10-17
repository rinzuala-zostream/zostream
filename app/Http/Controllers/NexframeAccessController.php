<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class NexframeAccessController extends Controller
{
    private const SESSION_KEY = 'nexframe_dashboard_authenticated';

    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'authenticated' => $request->session()->get(self::SESSION_KEY) === true,
            'configured' => $this->configured(),
            'partnership_started_at' => config('services.nexframe_dashboard.partnership_started_at'),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        if (! $this->configured()) {
            return response()->json([
                'message' => 'NextFrame dashboard credentials have not been configured.',
            ], 503);
        }

        $expectedEmail = strtolower(trim((string) config('services.nexframe_dashboard.email')));
        $passwordHash = (string) config('services.nexframe_dashboard.password_hash');
        $emailMatches = hash_equals($expectedEmail, strtolower(trim($validated['email'])));

        if (! $emailMatches || ! Hash::check($validated['password'], $passwordHash)) {
            return response()->json(['message' => 'The email or password is incorrect.'], 422);
        }

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, true);

        return response()->json(['authenticated' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->regenerateToken();

        return response()->json(['authenticated' => false]);
    }

    private function configured(): bool
    {
        return filled(config('services.nexframe_dashboard.email'))
            && filled(config('services.nexframe_dashboard.password_hash'));
    }
}
