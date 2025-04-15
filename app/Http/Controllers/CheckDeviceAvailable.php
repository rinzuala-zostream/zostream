<?php

namespace App\Http\Controllers;

use App\Models\UserDeviceModel;
use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class CheckDeviceAvailable extends Controller
{
    private $validApiKey;
    protected $subscriptionController;

    public function __construct(SubscriptionController $subscriptionController)
    {
        $this->validApiKey = config('app.api_key');
        $this->subscriptionController = $subscriptionController; // fixed
    }

    public function checkDeviceAvailability(Request $request)
    {

        $apiKey = $request->header('api_key');
    
        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"]);
        }

        $request->validate([
            'user_id' => 'required|string',
            'device_id' => 'required|string',
        ]);

        $user_id = $request->user_id;
        $device_id = $request->device_id;

        // Check if user exists
        $userExists = UserModel::where('uid', $user_id)->exists();
        if (!$userExists) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found'
            ], 404);
        }

        // Fetch subscription data
        $subscriptionData = $this->fetchSubscriptionData($user_id, $apiKey);
        if (!is_array($subscriptionData)) {
            return response()->json([
                "status" => "error",
                "message" => "Subscription data fetch failed."
            ]);
        }

        $max_devices = $subscriptionData['device_support'] ?? 1;

        // Check registered devices
        $registeredDevices = UserDeviceModel::where('user_id', $user_id)->pluck('device_id')->toArray();
        $deviceCount = count($registeredDevices);

        // Check if device already exists
        $deviceExists = UserDeviceModel::where('user_id', $user_id)->where('device_id', $device_id)->first();

        if ($deviceExists) {
            return response()->json([
                "status" => "success",
                "message" => "Device exists",
                "deviceData" => $deviceExists
            ]);
        } else {
            if ($deviceCount < $max_devices) {
                return response()->json([
                    "status" => "success",
                    "message" => "Device register available"
                ]);
            } else {
                return response()->json([
                    "status" => "error",
                    "message" => "Device limit reached. Upgrade your plan to add more devices."
                ]);
            }
        }
    }

    private function fetchSubscriptionData($user_id, $apiKey)
    {
        $deviceRequest = new Request([
            'id' => $user_id
        ]);

        $deviceRequest->headers->set('api_key', $apiKey);

        $response = $this->subscriptionController->getSubscription($deviceRequest);

        // Return decoded JSON if response is a JsonResponse
        if (method_exists($response, 'getData')) {
            return json_decode(json_encode($response->getData()), true); // convert object to array
        }

        return null;
    }
}
