<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\New\Plan;
use App\Models\New\Subscription;
use App\Models\New\Devices;
use App\Models\New\ActiveStream;
use App\Models\New\StreamEvent;

class NewStreamController extends Controller
{
    protected $lockTTL = 5000; // ms

    // 🔧 Redis Key Helpers
    private function zsetKey($subId, $type)
    {
        return "z:active_streams:{$subId}:{$type}";
    }
    private function hashKey($subId, $type, $devId)
    {
        return "h:stream:{$subId}:{$type}:{$devId}";
    }
    private function lockKey($subId, $type)
    {
        return "lock:subscription:{$subId}:{$type}";
    }

    // 🔐 Redis Lock
    private function acquireLock($key, $ttl)
    {
        $token = Str::random(16);
        return Redis::set($key, $token, 'NX', 'PX', $ttl) ? $token : false;
    }

    private function releaseLock($key, $token)
    {
        if (!$token)
            return;
        try {
            if (Redis::get($key) === $token)
                Redis::del($key);
        } catch (\Throwable $e) {
            \Log::warning("Failed to release lock {$key}: " . $e->getMessage());
        }
    }

    // 🧩 Start Stream
    public function start(Request $request)
    {
        $subscriptionId = $request->input('subscription_id');
        $deviceToken = $request->header('Device-Token');

        if (!$subscriptionId || !$deviceToken)
            return response()->json([
                'status' => 'error',
                'title' => 'Missing Parameters',
                'message' => 'subscription_id & Device-Token required'
            ], 400);

        $device = Devices::where('device_token', $deviceToken)->first();
        if (!$device)
            return response()->json([
                'status' => 'error',
                'title' => 'Device Not Registered',
                'message' => 'Your device is not recognized.'
            ], 404);

        $subscription = Subscription::find($subscriptionId);
        if (!$subscription)
            return response()->json([
                'status' => 'error',
                'title' => 'Subscription Not Found',
                'message' => 'Subscription ID invalid.'
            ], 404);

        if (Carbon::parse($subscription->end_at)->isPast())
            return response()->json([
                'status' => 'error',
                'title' => 'Subscription Expired',
                'message' => 'Please renew to continue streaming.'
            ], 403);

        $plan = Plan::find($subscription->plan_id);
        if (!$plan)
            return response()->json([
                'status' => 'error',
                'title' => 'Plan Not Found',
                'message' => 'Cannot retrieve your plan.'
            ], 500);

        $type = strtolower(trim($device->device_type));
        $limit = match ($type) {
            'mobile' => $plan->device_limit_mobile ?? 1,
            'browser' => $plan->device_limit_browser ?? 1,
            'tv' => $plan->device_limit_tv ?? 1,
            default => 1,
        };

        $lockKey = $this->lockKey($subscriptionId, $type);
        $token = $this->acquireLock($lockKey, $this->lockTTL);
        if (!$token)
            return response()->json([
                'status' => 'error',
                'title' => 'Server Busy',
                'message' => 'Try again in a few seconds.'
            ], 429);

        try {
            $zsetKey = $this->zsetKey($subscriptionId, $type);

            $activeMembers = array_keys(Redis::zrange($zsetKey, 0, -1, true) ?: []);
            $ownerId = Devices::where('user_id', $subscription->user_id)
                ->where('device_type', $type)
                ->where('is_owner_device', true)
                ->value('id');

            $isOwner = (bool) $device->is_owner_device;
            $nonOwnerActiveCount = count(array_filter($activeMembers, fn($id) => $id != $ownerId));

            // ✅ Owner always allowed, shared devices only if within limit
            if (!$isOwner && $nonOwnerActiveCount >= ($limit - 1)) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Device Limit Reached',
                    'message' => "Shared device limit reached. Wait until owner renews subscription."
                ], 409);
            }

            // Start stream
            $streamToken = Str::uuid()->toString();
            $hashKey = $this->hashKey($subscriptionId, $type, $device->id);
            $now = Carbon::now()->timestamp;

            Redis::zadd($zsetKey, [$device->id => $now]);
            Redis::hmset($hashKey, [
                'stream_token' => $streamToken,
                'started_at' => $now,
                'last_ping' => $now,
                'status' => 'active'
            ]);

            ActiveStream::updateOrCreate(
                ['subscription_id' => $subscriptionId, 'device_id' => $device->id],
                ['device_type' => $type, 'stream_token' => $streamToken, 'started_at' => now(), 'last_ping' => now(), 'status' => 'active']
            );

            $activeMembers = array_keys(Redis::zrange($zsetKey, 0, -1, true) ?: []);
            $nonOwnerActiveCount = count(array_filter($activeMembers, fn($id) => $id != $ownerId));
            $totalActive = count($activeMembers);
            $remainingSlots = max(0, $limit - 1 - $nonOwnerActiveCount); // -1 because owner counts as 1

            return response()->json([
                'status' => 'success',
                'stream_token' => $streamToken,
                'max_quality' => $plan->quality,
                'current_active' => $totalActive,
                'device_limit' => $limit,
                'remaining_slots' => $remainingSlots
            ]);

        } finally {
            $this->releaseLock($lockKey, $token);
        }
    }

    // 🔁 Ping stream
    public function ping(Request $request)
    {
        $deviceToken = $request->header('Device-Token');
        $streamToken = $request->input('stream_token');

        $device = Devices::where('device_token', $deviceToken)->first();
        if (!$device)
            return response()->json(['status' => 'error', 'title' => 'Device Not Found', 'message' => 'Device not registered.'], 404);

        $subscription = Subscription::find($device->subscription_id);
        if (!$subscription)
            return response()->json(['status' => 'error', 'title' => 'Subscription Not Found', 'message' => 'No active subscription.'], 404);

        $hashKey = $this->hashKey($subscription->id, strtolower($device->device_type), $device->id);
        $storedToken = Redis::hget($hashKey, 'stream_token');
        if ($storedToken !== $streamToken)
            return response()->json(['status' => 'error', 'title' => 'Invalid Stream Token', 'message' => 'Token invalid.'], 401);

        Redis::hset($hashKey, 'last_ping', Carbon::now()->timestamp);
        return response()->json(['status' => 'success']);
    }

    public function stop(Request $request)
    {
        $deviceToken = $request->header('Device-Token');
        $streamToken = $request->input('stream_token');

        $device = Devices::where('device_token', $deviceToken)->first();
        if (!$device) {
            return response()->json([
                'status' => 'error',
                'title' => 'Device Not Found',
                'message' => 'Your device is not registered or has been removed.'
            ], 404);
        }

        $subscription = Subscription::find($device->subscription_id);
        if (!$subscription) {
            return response()->json([
                'status' => 'error',
                'title' => 'Subscription Not Found',
                'message' => 'No active subscription found for this device.'
            ], 404);
        }

        $type = strtolower(trim($device->device_type));
        $hashKey = $this->hashKey($subscription->id, $type, $device->id);
        $zsetKey = $this->zsetKey($subscription->id, $type);

        $storedToken = Redis::hget($hashKey, 'stream_token');
        if ($storedToken !== $streamToken) {
            return response()->json([
                'status' => 'error',
                'title' => 'Invalid Stream Token',
                'message' => 'Cannot stop the stream because the token is invalid or already expired.'
            ], 401);
        }

        Redis::zrem($zsetKey, $device->id);
        Redis::del($hashKey);

        ActiveStream::where('device_id', $device->id)
            ->where('subscription_id', $subscription->id)
            ->update(['status' => 'stopped', 'last_ping' => now()]);

        StreamEvent::create([
            'subscription_id' => $subscription->id,
            'device_id' => $device->id,
            'event_type' => 'stop',
        ]);

        return response()->json(['status' => 'success']);
    }

    // 🔁 Renew subscription (owner only)
    public function renew(Request $request)
    {
        $subId = $request->input('subscription_id');
        if (!$subId)
            return response()->json(['status' => 'error', 'title' => 'Missing Subscription ID', 'message' => 'Provide subscription_id.'], 400);

        $subscription = Subscription::find($subId);
        if (!$subscription)
            return response()->json(['status' => 'error', 'title' => 'Invalid Subscription', 'message' => 'Subscription does not exist.'], 404);

        $plan = Plan::find($subscription->plan_id);
        if (!$plan)
            return response()->json(['status' => 'error', 'title' => 'Plan Not Found', 'message' => 'Cannot retrieve plan.'], 500);

        // Extend subscription
        $newEnd = Carbon::parse($subscription->end_at)->addDays($plan->duration_days);
        $subscription->update(['end_at' => $newEnd]);

        $kept = [];
        $kicked = [];

        foreach (['mobile', 'browser', 'tv'] as $type) {
            $lockKey = $this->lockKey($subId, $type);
            $token = $this->acquireLock($lockKey, $this->lockTTL);
            if (!$token)
                continue;

            try {
                $zsetKey = $this->zsetKey($subId, $type);
                $limit = match ($type) {
                    'mobile' => $plan->device_limit_mobile ?? 1,
                    'browser' => $plan->device_limit_browser ?? 1,
                    'tv' => $plan->device_limit_tv ?? 1,
                    default => 1,
                };

                $activeDevices = array_keys(Redis::zrevrange($zsetKey, 0, -1, true) ?: []);

                // Owner always kept
                $owner = Devices::where('is_owner_device', true)
                    ->where('device_type', $type)
                    ->where('user_id', $subscription->user_id)
                    ->first();

                $keepList = $owner ? [$owner->id] : [];

                // Keep first (limit-1) shared devices
                foreach ($activeDevices as $d) {
                    if (!in_array($d, $keepList) && count($keepList) < $limit) {
                        $keepList[] = $d;
                    }
                }

                // Kick other shared devices
                foreach ($activeDevices as $d) {
                    if (!in_array($d, $keepList)) {
                        Redis::zrem($zsetKey, $d);
                        Redis::del($this->hashKey($subId, $type, $d));
                        $kicked[] = $d;
                    }
                }

                $kept = array_merge($kept, $keepList);

            } finally {
                $this->releaseLock($lockKey, $token);
            }
        }

        StreamEvent::create([
            'subscription_id' => $subId,
            'event_type' => 'renew',
            'event_data' => ['kept' => $kept, 'kicked' => $kicked]
        ]);

        return response()->json(['status' => 'success', 'kept_devices' => $kept, 'kicked_devices' => $kicked]);
    }
}