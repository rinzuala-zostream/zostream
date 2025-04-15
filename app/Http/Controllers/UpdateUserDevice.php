<?php

namespace App\Http\Controllers;

use App\Models\RegisterModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

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
        try {
            $apiKey = Crypt::decryptString($request->header('api_key'));
        } catch (\Exception $e) {
            return response()->json(["status" => "error", "message" => "Invalid API key format"], 401);
        }

        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
        }

        $request->validate([
            'uid' => 'required|string',
            'device_id' => 'required|string',
            'device_name' => 'required|string',
        ]);

        $user = RegisterModel::where('uid', $request->uid)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found.',
            ], 404);
        }

        $user->update([
            'device_id' => $request->device_id,
            'device_name' => $request->device_name,
        ]);

        // Register the device via DeviceManagementController
        try {
            $deviceRequest = new Request([
                'user_id' => $request->uid,
                'device_id' => $request->device_id,
                'device_name' => $request->device_name,
            ]);

            $deviceRequest->headers->set('api_key', Crypt::encryptString($apiKey));
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
