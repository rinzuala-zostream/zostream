<?php

namespace App\Http\Controllers;

use App\Models\RegisterModel;
use Illuminate\Http\Request;

class UpdateUserDevice extends Controller
{
    private $validApiKey;
    protected $deviceController;

    public function __construct(DeviceManagementController $deviceController)
    {
        $this->validApiKey = config('app.api_key');
        $this->deviceController = $deviceController;
    }

    public function updateDevice(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
        }

        // Validate query parameters
        $request->validate([
            'uid' => 'required|string',
            'device_id' => 'required|string',
            'device_name' => 'required|string',
        ]);

        $uid = $request->query('uid');
        $device_id = $request->query('device_id');
        $device_name = $request->query('device_name');

        $user = RegisterModel::where('uid', $uid)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found.',
            ], 404);
        }

        $user->update([
            'device_id' => $device_id,
            'device_name' => $device_name,
        ]);

        // Register the device via DeviceManagementController
        try {
            $deviceRequest = new Request([
                'user_id' => $uid,
                'device_id' => $device_id,
                'device_name' => $device_name,
            ]);

            $deviceRequest->headers->set('X-Api-Key', $apiKey);
            $this->deviceController->update($deviceRequest);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Device update failed: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json(["status" => "success", "message" => "User device updated"], 200);
    }
}
