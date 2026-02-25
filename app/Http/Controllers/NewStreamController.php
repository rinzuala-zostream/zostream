<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\New\Plan;
use App\Models\New\Subscription;
use App\Models\New\Devices;
use App\Models\New\ActiveStream;
use App\Models\New\StreamEvent;
use Illuminate\Support\Facades\DB;

class NewStreamController extends Controller
{
    protected $streamTimeout = 300; // seconds, 5 minutes

    // 🧩 Start streaming
    public function start(Request $request)
    {
        $subscriptionId = $request->input('subscription_id');
        $deviceToken = $request->header('Device-Token');

        if (!$subscriptionId || !$deviceToken) {
            return response()->json([
                'status' => 'error',
                'title' => 'Missing Parameters',
                'message' => 'Please provide subscription_id and Device-Token header.'
            ], 400);
        }

        $device = Devices::where('device_token', $deviceToken)->first();
        if (!$device) {
            return response()->json([
                'status' => 'error',
                'title' => 'Device Not Registered',
                'message' => 'Your device is not recognized.'
            ], 404);
        }

        $subscription = Subscription::find($subscriptionId);
        if (!$subscription || Carbon::parse($subscription->end_at)->isPast()) {
            return response()->json([
                'status' => 'error',
                'title' => 'Subscription Invalid',
                'message' => 'Subscription not found or expired.'
            ], 403);
        }

        $plan = Plan::find($subscription->plan_id);
        if (!$plan) {
            return response()->json([
                'status' => 'error',
                'title' => 'Plan Not Found',
                'message' => 'Plan does not exist.'
            ], 500);
        }

        $type = strtolower(trim($device->device_type));
        $limit = match ($type) {
            'mobile' => $plan->device_limit_mobile ?? 1,
            'browser' => $plan->device_limit_browser ?? 1,
            'tv' => $plan->device_limit_tv ?? 1,
            default => 1,
        };

        // Cleanup stale sessions
        $timeout = Carbon::now()->subSeconds($this->streamTimeout);
        ActiveStream::where('subscription_id', $subscriptionId)
            ->where('device_type', $type)
            ->where('status', 'active')
            ->where('last_ping', '<', $timeout)
            ->update(['status' => 'stopped']);

        // Count active non-owner devices
        $activeNonOwner = ActiveStream::where('subscription_id', $subscriptionId)
            ->where('device_type', $type)
            ->where('status', 'active')
            ->where('is_owner_device', false)
            ->count();

        $isOwner = (bool) $device->is_owner_device;

        if (!$isOwner && $activeNonOwner >= $limit) {
            return response()->json([
                'status' => 'error',
                'title' => 'Device Limit Reached',
                'message' => "{$type} device limit ({$limit}) reached."
            ], 409);
        }

        // Start or reactivate stream
        $streamToken = Str::uuid()->toString();
        $now = Carbon::now();

        $activeStream = ActiveStream::updateOrCreate(
            [
                'subscription_id' => $subscriptionId,
                'device_id' => $device->id
            ],
            [
                'device_type' => $type,
                'stream_token' => $streamToken,
                'started_at' => $now,
                'last_ping' => $now,
                'status' => 'active',
                'is_owner_device' => $isOwner
            ]
        );

        $device->update(['status' => 'active']);

        StreamEvent::create([
            'subscription_id' => $subscriptionId,
            'device_id' => $device->id,
            'event_type' => 'start',
            'event_data' => ['ts' => $now->timestamp]
        ]);

        return response()->json([
            'status' => 'success',
            'stream_token' => $streamToken,
            'max_quality' => $plan->quality,
            'current_active' => ActiveStream::where('subscription_id', $subscriptionId)
                ->where('device_type', $type)
                ->where('status', 'active')
                ->count(),
            'device_limit' => $limit,
            'remaining_slots' => max(0, $limit - ($isOwner ? 0 : $activeNonOwner + 1))
        ]);
    }

    // 🧹 Ping stream
    public function ping(Request $request)
    {
        $deviceToken = $request->header('Device-Token');
        $streamToken = $request->input('stream_token');

        $device = Devices::where('device_token', $deviceToken)->first();
        if (!$device) return response()->json(['status' => 'error', 'message' => 'Device not found'], 404);

        $activeStream = ActiveStream::where('device_id', $device->id)
            ->where('stream_token', $streamToken)
            ->first();

        if (!$activeStream || $activeStream->status !== 'active') {
            return response()->json(['status' => 'error', 'message' => 'Invalid or inactive stream'], 403);
        }

        $activeStream->update(['last_ping' => Carbon::now()]);

        StreamEvent::create([
            'subscription_id' => $activeStream->subscription_id,
            'device_id' => $device->id,
            'event_type' => 'ping',
            'event_data' => ['ts' => Carbon::now()->timestamp]
        ]);

        return response()->json(['status' => 'success']);
    }

    // 🛑 Stop stream
    public function stop(Request $request)
    {
        $deviceToken = $request->header('Device-Token');
        $streamToken = $request->input('stream_token');

        $device = Devices::where('device_token', $deviceToken)->first();
        if (!$device) return response()->json(['status' => 'error', 'message' => 'Device not found'], 404);

        $activeStream = ActiveStream::where('device_id', $device->id)
            ->where('stream_token', $streamToken)
            ->first();

        if ($activeStream) {
            $activeStream->update(['status' => 'inactive', 'last_ping' => Carbon::now()]);
            $device->update(['status' => 'inactive']);

            StreamEvent::create([
                'subscription_id' => $activeStream->subscription_id,
                'device_id' => $device->id,
                'event_type' => 'stop',
            ]);
        }

        return response()->json(['status' => 'success']);
    }
}