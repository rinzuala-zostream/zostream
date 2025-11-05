<?php

namespace App\Http\Controllers;

use App\Models\QuizApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class QuizApiKeyController extends Controller
{
    // Generate a new API key
    public function create(Request $request)
    {
        $data = $request->validate([
            'owner_name' => 'required|string|max:100',
            'email' => 'nullable|email|max:150',
            'description' => 'nullable|string|max:255',
            'valid_days' => 'nullable|integer|min:1'
        ]);

        $apiKey = 'quiz_' . Str::random(40);
        $validUntil = isset($data['valid_days'])
            ? Carbon::now()->addDays($data['valid_days'])
            : null;

        $key = QuizApiKey::create([
            'api_key' => $apiKey,
            'owner_name' => $data['owner_name'],
            'email' => $data['email'] ?? null,
            'description' => $data['description'] ?? null,
            'valid_until' => $validUntil,
        ]);

        return response()->json([
            'status' => 'success',
            'api_key' => $key->api_key,
            'valid_until' => $key->valid_until,
        ]);
    }

    // Verify if an API key is valid
    public function verify(Request $request)
    {
        $keyValue = $request->header('X-Api-Key') ?? $request->query('api_key');
        if (!$keyValue) {
            return response()->json(['status' => 'error', 'message' => 'Missing API key'], 400);
        }

        $key = QuizApiKey::where('api_key', $keyValue)->first();
        if (!$key || !$key->isValid()) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or expired API key'], 403);
        }

        $key->increment('usage_count');
        $key->update(['last_used_at' => now()]);

        return response()->json(['status' => 'ok', 'message' => 'API key valid']);
    }

    // List all keys (admin only)
    public function index()
    {
        return response()->json([
            'status' => 'ok',
            'data' => QuizApiKey::orderByDesc('created_at')->get()
        ]);
    }

    // Deactivate an API key
    public function deactivate($id)
    {
        $key = QuizApiKey::findOrFail($id);
        $key->update(['is_active' => false]);

        return response()->json(['status' => 'success', 'message' => 'API key deactivated']);
    }
}
