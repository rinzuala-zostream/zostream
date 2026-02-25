<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\New\Devices;

class DeviceController extends Controller
{
    /**
     * List all devices with pagination
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);

        $devices = Devices::orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $devices
        ]);
    }

    /**
     * Show a single device by ID
     */
    public function show($id)
    {
        $device = Devices::find($id);

        if (!$device) {
            return response()->json([
                'status' => 'error',
                'message' => 'Device not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $device
        ]);
    }

    /**
     * Get all devices for a user (owner + shared)
     */
    public function getByUser(Request $request, $userId)
    {
        $perPage = $request->get('per_page', 15);

        $devices = Devices::where('user_id', $userId)
            ->orWhere(function ($query) use ($userId) {
                $query->where('is_owner_device', false)
                      ->where('shared_to_user_id', $userId);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        if ($devices->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No devices found for this user'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $devices
        ]);
    }

    /**
     * Create a new device
     */
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'subscription_id' => 'required|integer',
            'device_token' => 'required|string',
            'device_name' => 'required|string',
            'device_type' => 'required|string',
            'status' => 'nullable|string|in:active,inactive,blocked',
            'is_owner_device' => 'nullable|boolean',
            'shared_to_user_id' => 'nullable|integer',
        ]);

        $device = Devices::create($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Device created successfully',
            'data' => $device
        ], 201);
    }

    /**
     * Update a device
     */
    public function update(Request $request, $id)
    {
        $device = Devices::find($id);

        if (!$device) {
            return response()->json([
                'status' => 'error',
                'message' => 'Device not found'
            ], 404);
        }

        $device->update($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Device updated successfully',
            'data' => $device
        ]);
    }

    /**
     * Delete a device
     */
    public function destroy($id)
    {
        $device = Devices::find($id);

        if (!$device) {
            return response()->json([
                'status' => 'error',
                'message' => 'Device not found'
            ], 404);
        }

        $device->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Device deleted successfully'
        ]);
    }
}