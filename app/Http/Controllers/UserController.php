<?php

namespace App\Http\Controllers;

use App\Models\UserModel;
use Illuminate\Http\Request;
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
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key'], 401);
        }

        // Validate query parameters
        $request->validate([
            'mail' => 'nullable|email',
            'uid' => 'nullable|string',
        ]);

        try {
            $mail = $request->query('mail');
            $uid = $request->query('uid');

            $user = null;
            if (!empty($mail)) {
                $user = UserModel::where('mail', $mail)->first();
            } elseif (!empty($uid)) {
                $user = UserModel::where('uid', $uid)->first();
            }

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

    public function updateDob(Request $request)
    {
        // Validate required input
        $request->validate([
            'uid' => 'required|string',
            'dob' => 'required|date',
        ]);

        $uid = $request->query('uid');
        $dob = $request->query('dob');

        // Check if the user exists
        $user = UserModel::where('uid', $uid)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'UID not found in the database'
            ], 404);
        }

        try {
            UserModel::where('uid', $uid)
                ->update(['dob' => $dob]);

            return response()->json([
                'status' => 'success',
                'message' => 'success'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updateToken(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key'], 401);
        }

        // Validate request
        $request->validate([
            'uid'   => 'required|string',
            'token' => 'required|string',
        ]);

        $uid   = $request->input('uid');
        $token = $request->input('token');

        try {

            $user = UserModel::where('uid', $uid)->first();

            if ($user) {
                UserModel::where('uid', $uid)
                    ->update(['token' => $token]);

                return response()->json(['status' => 'success', 'message' => 'Record updated successfully']);
            } else {
                return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
