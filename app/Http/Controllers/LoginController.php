<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionModel;
use App\Models\BrowserSubscriptionModel;
use App\Models\TVSubscriptionModel;
use App\Models\UserDeviceModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LoginController extends Controller
{
    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key');
    }

    public function login(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
        }

        $request->validate([
            'uid' => 'required|string',
            'device_id' => 'required|string',
            'device_name' => 'required|string',
            'device_type' => 'required|string|in:Browser,TV,Mobile',
        ]);

        $uid = $request->uid;
        $deviceId = $request->device_id;
        $deviceType = $request->device_type;
        $deviceName = $request->device_name;

        try {
            // Check if device is already registered
            $existingDevice = UserDeviceModel::where('user_id', $uid)
                ->where('device_id', $deviceId)
                ->first();

            if ($existingDevice) {
                $existingDevice->update(['last_login' => now()]);
                return response()->json(['status' => 'success', 'message' => 'Login successful']);
            }

            // Get current device count
            $deviceCount = UserDeviceModel::where('user_id', $uid)
                ->where('device_type', $deviceType)
                ->count();

            // Pick subscription model based on device type
            $subscriptionModel = match ($deviceType) {
                'Browser' => BrowserSubscriptionModel::class,
                'TV' => TVSubscriptionModel::class,
                'Mobile' => SubscriptionModel::class,
            };

            // Fetch subscription
            $subscription = $subscriptionModel::where('id', $uid)->first();

            // Default allowed devices
            $allowedDevices = 1;

            if ($subscription) {
                $months = floor($subscription->period / 30);
                $allowedDevices = match (true) {
                    $months < 1 => 1,
                    $months <= 1 => 2,
                    $months <= 4 => 3,
                    $months <= 6 => 3,
                    default => 4,
                };
            }

            // Block login if limit reached
            if ($deviceCount >= $allowedDevices) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Device limit reached',
                    'limit' => $allowedDevices,
                    'used' => $deviceCount
                ], 403);
            }

            // Register new device
            UserDeviceModel::create([
                'user_id' => $uid,
                'device_id' => $deviceId,
                'device_type' => $deviceType,
                'device_name' => $deviceName,
                'role' => 'owner',
                'last_login' => now(),
                'created_at' => now()
            ]);

            return response()->json(['status' => 'success', 'message' => 'Device registered and login successful']);

        } catch (\Exception $e) {
            Log::error('Login Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Login failed']);
        }
    }

    public function logout(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
        }

        $request->validate([
            'uid' => 'required|string',
            'device_id' => 'required|string',
        ]);

        try {
            UserDeviceModel::where('user_id', $request->uid)
                ->where('device_id', $request->device_id)
                ->delete();

            return response()->json(['status' => 'success', 'message' => 'Logged out and device removed']);
        } catch (\Exception $e) {
            Log::error('Logout Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Logout failed']);
        }
    }
}
