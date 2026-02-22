<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RegisterModel;
use App\Models\UserDeviceModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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
        // Force all responses as JSON
        $request->headers->set('Accept', 'application/json');

        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== $this->validApiKey) {
            return response()->json([
                "status" => "error",
                "message" => "Invalid API key"
            ], 403);
        }

        try {
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
                'num' => 'nullable|integer'
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }

        try {
            $user = RegisterModel::create($validatedData);

            if ($user) {
                $role = 'owner';
                UserDeviceModel::create([
                    'user_id' => $validatedData['uid'],
                    'device_id' => $validatedData['device_id'],
                    'device_name' => $validatedData['device_name'],
                    'role' => $role,
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'User and device registered successfully',
                    'data' => [
                        'user' => $user,
                        'device' => [
                            'device_id' => $validatedData['device_id'],
                            'device_name' => $validatedData['device_name'],
                            'role' => $role,
                        ],
                    ],
                ]);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User registration failed',
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Database Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Database error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}