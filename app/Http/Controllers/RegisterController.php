<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RegisterModel;
use App\Models\UserDeviceModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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
            return response()->json(["status" => "error", "message" => "Invalid API key"]);
        }

        // Validate registration data
        $validatedData = $request->validate([
            'call' => 'required|string',
            'created_date' => 'required|date',
            'device_id' => 'required|string',
            'dob' => 'nullable|date',
            'edit_date' => 'nullable|date',
            'img' => 'nullable|string',
            'isACActive' => 'boolean',
            'isAccountComplete' => 'boolean',
            'khua' => 'nullable|string',
            'lastLogin' => 'nullable|date',
            'mail' => 'required|email',
            'name' => 'required|string',
            'uid' => 'required|string',
            'veng' => 'nullable|string',
            'device_name' => 'required|string',
            'token' => 'nullable|string'
            
        ]);

        try {
            // Register the user
            $response = RegisterModel::create($validatedData);

            // Check if user registration was successful
            if ($response) {
                try {
                
                    $role =  'owner';
                    UserDeviceModel::create([ 
                        'user_id' => $validatedData['uid'],
                        'device_id' => $validatedData['device_id'],
                        'device_name' => $validatedData['device_name'],
                        'role' => $role
                    ]);
        
                    return response()->json(['status' => 'success', 'message' => 'Device registered successfully']);
                } catch (\Exception $e) {
                    Log::error('Database Error: ' . $e->getMessage());
                    return response()->json(['status' => 'error', 'message' => 'Database error']);
                }
            } else {
                return response()->json(['status' => 'error', 'message' => 'User registration failed']);
            }
        } catch (\Exception $e) {
            Log::error('Database Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
