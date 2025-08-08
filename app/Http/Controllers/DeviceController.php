<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Device;

class DeviceController extends Controller
{
    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key');
    }

    public function registerDevice(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
        }

        $request->validate([
            'uid' => 'required|string',
            'device_id' => 'required|string',
            'device_name' => 'nullable|string',
            'device_type' => 'nullable|string',
            'device_support' => 'required|integer',
        ]);

        // Check if device is already registered
        $existing = Device::where('uid', $request->uid)
            ->where('device_id', $request->device_id)
            ->first();

        if ($existing) {
            return response()->json(['status' => 'already_registered']);
        }

        // Count how many devices are already registered
        $count = Device::where('uid', $request->uid)->count();
        if ($count >= $request->device_support) {
            return response()->json(['status' => 'error', 'message' => 'Device limit reached'], 403);
        }

        // Register the new device
        Device::create([
            'uid' => $request->uid,
            'device_id' => $request->device_id,
            'device_name' => $request->device_name ?? 'Unknown',
            'device_type' => $request->device_type ?? 'Unknown',
        ]);

        return response()->json(['status' => 'device_registered']);
    }
}
