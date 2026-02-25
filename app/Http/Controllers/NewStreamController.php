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
    protected $streamTimeout = 60; // 5 minutes
    protected $lockTTL = 5000; // milliseconds

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

    // 🔐 Acquire Redis lock
    private function acquireLock($key, $ttl)
    {
        $token = Str::random(16);
        return Redis::set($key, $token, 'NX', 'PX', $ttl) ? $token : false;
    }

    // 🔓 Release Redis lock
    private function releaseLock($key, $token)
    {
        if (!$token)
            return;
        try {
            if (Redis::get($key) === $token) {
                Redis::del($key);
            }
        } catch (\Throwable $e) {
            \Log::warning("Failed to release lock {$key}: " . $e->getMessage());
        }
    }

    // 🧩 Start streaming
    public function start(Request $request)
    {
        $subscriptionId = $request->input('subscription_id');
        $deviceToken = $request->header('Device-Token');

        if (!$subscriptionId || !$deviceToken) {
            return response()->json([
                'status' => 'error',
                'title' => 'Missing Parameters',
                'message' => 'Please provide both subscription_id and Device-Token header.'
            ], 400);
        }

        $device = Devices::where('device_token', $deviceToken)->first();
        if (!$device) {
            return response()->json([
                'status' => 'error',
                'title' => 'Device Not Registered',
                'message' => 'Your device is not recognized. Please log in again or contact support.'
            ], 404);
        }

        $subscription = Subscription::find($subscriptionId);
        if (!$subscription) {
            return response()->json([
                'status' => 'error',
                'title' => 'Subscription Not Found',
                'message' => 'The subscription ID provided does not exist.'
            ], 404);
        }

        if (Carbon::parse($subscription->end_at)->isPast()) {
            return response()->json([
                'status' => 'error',
                'title' => 'Subscription Expired',
                'message' => 'Your subscription has expired. Please renew to continue streaming.'
            ], 403);
        }

        $plan = Plan::find($subscription->plan_id);
        if (!$plan) {
            return response()->json([
                'status' => 'error',
                'title' => 'Plan Not Found',
                'message' => 'There was an issue retrieving your plan. Contact support.'
            ], 500);
        }

        $type = strtolower(trim($device->device_type));
        $limit = match ($type) {
            'mobile' => $plan->device_limit_mobile ?? 1,
            'browser' => $plan->device_limit_browser ?? 1,
            'tv' => $plan->device_limit_tv ?? 1,
            default => 1,
        };

        $lockKey = $this->lockKey($subscriptionId, $type);
        $token = $this->acquireLock($lockKey, $this->lockTTL);
        if (!$token) {
            return response()->json([
                'status' => 'error',
                'title' => 'Server Busy',
                'message' => 'Unable to start streaming. Please try again in a few seconds.'
            ], 429);
        }

        try {
            $now = Carbon::now()->timestamp;
            $zsetKey = $this->zsetKey($subscriptionId, $type);

            // 1️⃣ Remove stale sessions
            $members = Redis::zrange($zsetKey, 0, -1, true) ?: [];
            foreach ($members as $member => $score) {
                if ($now - (int) $score > $this->streamTimeout) {
                    Redis::zrem($zsetKey, $member);
                    Redis::del($this->hashKey($subscriptionId, $type, $member));
                }
            }

            // 2️⃣ Reload active members and count active shared devices
            $activeMembers = array_keys(Redis::zrange($zsetKey, 0, -1, true) ?: []);
            $ownerId = Devices::where('user_id', $subscription->user_id)
                ->where('device_type', $type)
                ->where('is_owner_device', true)
                ->value('id');

            $isOwner = (bool) $device->is_owner_device;
            $nonOwnerActiveCount = 0;
            foreach ($activeMembers as $memberId) {
                if ($memberId != $ownerId) {
                    $status = Redis::hget($this->hashKey($subscriptionId, $type, $memberId), 'status');
                    if ($status === 'active')
                        $nonOwnerActiveCount++;
                }
            }

            // 3️⃣ Enforce device limit for shared devices
            if (!$isOwner && $nonOwnerActiveCount >= $limit) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Device Limit Reached',
                    'message' => "You have reached the maximum number of allowed {$type} devices ({$limit}). Stop a stream on another device or upgrade your plan.",
                ], 409);
            }

            // 4️⃣ Start or reactivate stream
            $streamToken = Str::uuid()->toString();
            $hashKey = $this->hashKey($subscriptionId, $type, $device->id);

            Redis::zadd($zsetKey, [$device->id => $now]);
            Redis::hmset($hashKey, [
                'stream_token' => $streamToken,
                'started_at' => $now,
                'last_ping' => $now,
                'status' => 'active'
            ]);

            ActiveStream::updateOrCreate(
                ['subscription_id' => $subscriptionId, 'device_id' => $device->id],
                [
                    'device_type' => $type,
                    'stream_token' => $streamToken,
                    'started_at' => now(),
                    'last_ping' => now(),
                    'status' => 'active'
                ]
            );

            // 5️⃣ Compute accurate active count & remaining slots
            $activeMembers = array_keys(Redis::zrange($zsetKey, 0, -1, true) ?: []);
            $nonOwnerActiveCount = 0;
            foreach ($activeMembers as $memberId) {
                if ($memberId != $ownerId) {
                    $status = Redis::hget($this->hashKey($subscriptionId, $type, $memberId), 'status');
                    if ($status === 'active')
                        $nonOwnerActiveCount++;
                }
            }

            $totalActive = count($activeMembers);
            $remainingSlots = max(0, $limit - $nonOwnerActiveCount - ($isOwner ? 0 : 1));

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

        $plan = Plan::find($subscription->plan_id);
        if (!$plan) {
            return response()->json([
                'status' => 'error',
                'title' => 'Plan Not Found',
                'message' => 'There was an issue retrieving your plan. Contact support.'
            ], 500);
        }

        $type = strtolower(trim($device->device_type));
        $zsetKey = $this->zsetKey($subscription->id, $type);
        $hashKey = $this->hashKey($subscription->id, $type, $device->id);

        $storedToken = Redis::hget($hashKey, 'stream_token');
        if ($storedToken !== $streamToken) {
            return response()->json([
                'status' => 'error',
                'title' => 'Invalid Stream Token',
                'message' => 'Your session token is invalid. Please restart the stream on your device.'
            ], 401);
        }

        $status = Redis::hget($hashKey, 'status');
        if ($status !== 'active') {
            return response()->json([
                'status' => 'error',
                'title' => 'Device Not Active',
                'message' => 'Your device is currently inactive and cannot ping the stream.'
            ], 403);
        }

        $now = Carbon::now()->timestamp;

        // Cleanup stale sessions
        $members = Redis::zrange($zsetKey, 0, -1, true) ?: [];
        foreach ($members as $member => $score) {
            if ($now - (int) $score > $this->streamTimeout) {
                Redis::zrem($zsetKey, $member);
                Redis::del($this->hashKey($subscription->id, $type, $member));
            }
        }

        // Update ping
        Redis::hset($hashKey, 'last_ping', $now);
        Redis::zadd($zsetKey, [$device->id => $now]);

        StreamEvent::create([
            'subscription_id' => $subscription->id,
            'device_id' => $device->id,
            'event_type' => 'ping',
            'event_data' => ['ts' => $now],
        ]);

        return response()->json(['status' => 'success']);
    }

    // 🧹 Stop stream
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

        Redis::hset($hashKey, 'status', 'inactive');
        Redis::zrem($zsetKey, $device->id);

        ActiveStream::where('device_id', $device->id)
            ->where('subscription_id', $subscription->id)
            ->update(['status' => 'inactive', 'last_ping' => now()]);

        StreamEvent::create([
            'subscription_id' => $subscription->id,
            'device_id' => $device->id,
            'event_type' => 'stop',
        ]);

        return response()->json(['status' => 'success']);
    }

    // 🔁 Renew subscription
    public function renew(Request $request)
    {
        $subId = $request->input('subscription_id');
        if (!$subId) {
            return response()->json([
                'status' => 'error',
                'title' => 'Missing Subscription ID',
                'message' => 'Please provide subscription_id to renew.'
            ], 400);
        }

        $subscription = Subscription::find($subId);
        if (!$subscription) {
            return response()->json([
                'status' => 'error',
                'title' => 'Invalid Subscription',
                'message' => 'The subscription does not exist.'
            ], 404);
        }

        $plan = Plan::find($subscription->plan_id);
        if (!$plan) {
            return response()->json([
                'status' => 'error',
                'title' => 'Plan Not Found',
                'message' => 'There was an issue retrieving your plan. Contact support.'
            ], 500);
        }

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

                $owner = Devices::where('is_owner_device', true)
                    ->where('device_type', $type)
                    ->where('user_id', $subscription->user_id)
                    ->first();

                $keepList = [];
                if ($owner)
                    $keepList[] = $owner->id;

                foreach ($activeDevices as $d) {
                    if (!in_array($d, $keepList) && count($keepList) < $limit) {
                        $keepList[] = $d;
                    }
                }

                foreach ($activeDevices as $d) {
                    if (!in_array($d, $keepList)) {
                        // mark blocked
                        Redis::hset($this->hashKey($subId, $type, $d), 'status', 'blocked');
                        ActiveStream::where('subscription_id', $subId)
                            ->where('device_id', $d)
                            ->update(['status' => 'blocked']);
                        $kicked[] = $d;
                    } else {
                        // mark active
                        Redis::hset($this->hashKey($subId, $type, $d), 'status', 'active');
                        ActiveStream::updateOrCreate(
                            ['subscription_id' => $subId, 'device_id' => $d],
                            [
                                'device_type' => $type,
                                'status' => 'active',
                                'last_ping' => now()
                            ]
                        );
                        $kept[] = $d;
                    }
                }

            } finally {
                $this->releaseLock($lockKey, $token);
            }
        }

        StreamEvent::create([
            'subscription_id' => $subId,
            'event_type' => 'renew',
            'event_data' => ['kept' => $kept, 'kicked' => $kicked]
        ]);

        return response()->json([
            'status' => 'success',
            'kept_devices' => $kept,
            'kicked_devices' => $kicked
        ]);
    }
}