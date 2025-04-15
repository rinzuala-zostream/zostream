<?php

namespace App\Http\Controllers;

use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key'); // Get the API key from the config
    }

    public function getUserData(Request $request)
    {
        try {
            $apiKey = Crypt::decryptString($request->header('api_key'));
        } catch (\Exception $e) {
            return response()->json(["status" => "error", "message" => "Invalid API key format"], 401);
        }

        // Validate the input (email or uid)
        $validatedData = $request->validate([
            'mail' => 'nullable|email', // Validate email if present
            'uid' => 'nullable|string', // Validate uid if present
        ]);

        try {
            // Fetch user by email or uid
            $user = null;
            if (isset($validatedData['mail'])) {
                $user = UserModel::where('mail', $validatedData['mail'])->first();
            } elseif (isset($validatedData['uid'])) {
                $user = UserModel::where('uid', $validatedData['uid'])->first();
            }

            // Check if user is found
            if ($user) {
                return response()->json($user);
            } else {
                return response()->json(['status' => 'error', 'message' => 'User not found']);
            }
        } catch (\Exception $e) {
            Log::error('Error fetching user data: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Internal Server Error']);
        }
    }
}
