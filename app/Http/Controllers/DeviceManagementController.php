<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use App\Models\UserDeviceModel;
use App\Models\UserModel;
use App\Http\Controllers\Controller;

class DeviceManagementController extends Controller
{

    private $validApiKey;
    public function __construct()
    {
        $this->validApiKey = config('app.api_key');

    }
    public function store(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
        }

        // Validate query parameters
        $request->validate([
            'user_id' => 'required|string',
            'device_id' => 'required|string',
            'device_name' => 'required|string'
        ]);

        try {
            $user_id = $request->query('user_id');
            $device_id = $request->query('device_id');
            $device_name = $request->query('device_name');

            $user = UserModel::where('uid', $user_id)->first();
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
            }

            $role = 'shared';

            UserDeviceModel::create([
                'user_id' => $user_id,
                'device_id' => $device_id,
                'device_name' => $device_name,
                'role' => $role
            ]);

            return response()->json(['status' => 'success', 'message' => 'Device registered successfully']);
        } catch (\Exception $e) {
            Log::error('Database Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Database error'], 500);
        }
    }

    public function delete(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => "Invalid API key"]);
        }

        $validated = $request->validate([
            'user_id' => 'required|string',
        ]);

        $user_id = $validated['user_id'];

        if (!$user_id) {
            return response()->json(["status" => "error", "message" => "Missing required user_id."]);
        }

        $sharedCount = UserDeviceModel::where('user_id', $user_id)
            ->where('role', 'shared')
            ->count();

        if ($sharedCount === 0) {
            return response()->json([
                "status" => "error",
                "message" => "No shared devices found for this user."
            ]);
        }

        $deleted = UserDeviceModel::where('user_id', $user_id)
            ->where('role', 'shared')
            ->delete();

        if ($deleted) {
            return response()->json([
                "status" => "success",
                "message" => "All shared devices removed successfully"
            ]);
        } else {
            return response()->json([
                "status" => "error",
                "message" => "Failed to delete shared devices."
            ]);
        }
    }

    public function get(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"]);
        }

        $request->validate([
            'user_id' => 'required|string',
            'device_id' => 'nullable|string',
        ]);

        $user_id = $request->query('user_id');
        $device_id = $request->query('device_id');

        if ($device_id) {
            // device_id is present and non-empty, fetch specific device
            $device = UserDeviceModel::where('user_id', $user_id)
                ->where('device_id', $device_id)
                ->first();

            if ($device) {
                return response()->json([
                    "status" => "success",
                    "message" => "Device found",
                    "deviceData" => $device
                ]);
            } else {
                return response()->json([
                    "status" => "error",
                    "message" => "Device not found"
                ]);
            }
        } else {
            // device_id is null, empty, or not provided — fetch all devices
            $devices = UserDeviceModel::where('user_id', $user_id)->get();

            if ($devices->isNotEmpty()) {
                return response()->json([
                    "status" => "success",
                    "message" => "Devices retrieved successfully",
                    "deviceData" => $devices
                ]);
            } else {
                return response()->json([
                    "status" => "error",
                    "message" => "No devices found for this user."
                ]);
            }
        }
    }

    public function update(Request $request)
    {

        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"]);
        }

        $validatedData = $request->validate([
            'user_id' => 'required|string',
            'device_id' => 'required|string',
            'device_name' => 'required|string'
        ]);

        $device = UserDeviceModel::where('user_id', $validatedData['user_id'])->where('role', 'owner')->first();
        if (!$device) {
            return response()->json(['status' => 'error', 'message' => 'Owner device not found']);
        }

        $device->update(['device_id' => $validatedData['device_id'], 'device_name' => $validatedData['device_name']]);

        return response()->json(['status' => 'success', 'message' => 'Device updated successfully']);
    }
}