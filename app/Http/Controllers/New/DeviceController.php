<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\New\Devices;
use App\Models\New\ActiveStream;
use App\Models\UserModel;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
    // List all devices with pagination
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

    // Search users and return only devices linked to the matching users
    public function search(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:2|max:180',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $term = trim((string) $request->query('q'));
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));
        $digits = preg_replace('/\D+/', '', $term);
        $phoneNeedle = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        $matchedUserIds = UserModel::query()
            ->where(function ($query) use ($term, $phoneNeedle) {
                $query->where('uid', 'like', '%' . $term . '%')
                    ->orWhere('mail', 'like', '%' . $term . '%')
                    ->orWhere('name', 'like', '%' . $term . '%')
                    ->orWhere('call', 'like', '%' . $term . '%')
                    ->orWhere('auth_phone', 'like', '%' . $term . '%');

                if ($phoneNeedle !== '') {
                    $normalizedCall = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(`call`, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
                    $normalizedAuthPhone = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(`auth_phone`, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";

                    $query->orWhereRaw("{$normalizedCall} LIKE ?", ['%' . $phoneNeedle . '%'])
                        ->orWhereRaw("{$normalizedAuthPhone} LIKE ?", ['%' . $phoneNeedle . '%']);
                }
            })
            ->select('uid');

        $devices = Devices::query()
            ->whereIn('user_id', $matchedUserIds)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json([
            'status' => 'success',
            'data' => $devices,
        ]);
    }

    // Show single device by ID
    public function show($id)
    {
        $device = Devices::find($id);
        if (!$device) {
            return response()->json(['status' => 'error', 'message' => 'Device not found'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $device]);
    }

    // Get all logged-in devices for a user
    public function getByUser(Request $request, $userId)
    {
        $perPage = $request->get('per_page', 15);

        $devices = Devices::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        if ($devices->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => 'No devices found for this user'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $devices]);
    }

    // Clear devices for a user
    public function clear(Request $request)
    {
        $request->validate([
            'user_id' => 'required|string|max:225',
            'device_type' => 'nullable|string',
            'device_token' => 'nullable|string',
        ]);

        $query = Devices::where('user_id', $request->user_id);

        if ($request->filled('device_type')) {
            $query->where('device_type', $request->device_type);
        }

        if ($request->filled('device_token')) {
            $query->where('device_token', $request->device_token);
        }

        $deviceIds = (clone $query)->pluck('id');
        $deletedCount = $deviceIds->count();

        if ($deletedCount === 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No devices found for the given filters'
            ], 404);
        }

        DB::transaction(function () use ($query, $deviceIds) {
            ActiveStream::whereIn('device_id', $deviceIds)
                ->where('status', 'active')
                ->update([
                    'status' => 'stopped',
                    'last_ping' => now(),
                ]);

            $query->delete();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Device data cleared successfully',
            'deleted_count' => $deletedCount
        ]);
    }

    // Create a device
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|string|max:225',
            'subscription_id' => 'required|integer',
            'device_token' => 'required|string',
            'device_name' => 'required|string',
            'device_type' => 'required|string',
            'status' => 'nullable|string|in:active,inactive,blocked',
            'is_owner_device' => 'nullable|boolean',
        ]);

        $device = Devices::create($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Device created successfully',
            'data' => $device
        ], 201);
    }

    // Update a device
    public function update(Request $request, $id)
    {
        $device = Devices::find($id);
        if (!$device) {
            return response()->json(['status' => 'error', 'message' => 'Device not found'], 404);
        }

        $device->update($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Device updated successfully',
            'data' => $device
        ]);
    }

    // Delete a device
    public function destroy($id)
    {
        $device = Devices::find($id);
        if (!$device) {
            return response()->json(['status' => 'error', 'message' => 'Device not found'], 404);
        }

        $device->delete();

        return response()->json(['status' => 'success', 'message' => 'Device deleted successfully']);
    }
}
