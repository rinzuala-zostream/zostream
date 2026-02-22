<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RegisterModel;
use App\Models\UserDeviceModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RegisterController extends Controller
{
    private $validApiKey;
    protected $deviceController;

    public function __construct(DeviceManagementController $deviceController)
    {
        $this->validApiKey = config('app.api_key');
        $this->deviceController = $deviceController;
    }

    public function store(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== $this->validApiKey) {
            return response()->json([
                "status" => "error",
                "message" => "Invalid API key"
            ], 403);
        }

        $validatedData = $request->validate([
            'call' => 'nullable|string',
            'created_date' => 'required|string',
            'device_id' => 'nullable|string',
            'dob' => 'nullable|string',
            'edit_date' => 'nullable|string',
            'img' => 'nullable|string',
            'isACActive' => 'boolean',
            'isAccountComplete' => 'boolean',
            'khua' => 'nullable|string',
            'lastLogin' => 'nullable|string',
            'mail' => 'nullable|email',
            'name' => 'nullable|string',
            'uid' => 'required|string',
            'veng' => 'nullable|string',
            'device_name' => 'nullable|string',
            'token' => 'nullable|string',
            'auth_phone' => 'nullable|string',
            'is_auth_phone_active' => 'boolean',
        ]);

        try {
            // Register user
            $user = RegisterModel::create($validatedData);

            if ($user) {
                try {
                    $role = 'owner';
                    UserDeviceModel::create([
                        'user_id' => $validatedData['uid'],
                        'device_id' => $validatedData['device_id'],
                        'device_name' => $validatedData['device_name'],
                        'role' => $role
                    ]);

                    return response()->json([
                        'status' => 'success',
                        'message' => 'User and device registered successfully',
                        'data' => [
                            'user' => $user,
                            'device' => [
                                'device_id' => $validatedData['device_id'],
                                'device_name' => $validatedData['device_name'],
                                'role' => $role
                            ]
                        ]
                    ]);
                } catch (\Exception $e) {
                    Log::error('Device registration error: ' . $e->getMessage());
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Device registration failed',
                        'error' => $e->getMessage()
                    ], 500);
                }
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User registration failed'
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Database Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Database error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}