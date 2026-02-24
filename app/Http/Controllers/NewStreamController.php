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
    protected $streamTimeout = 300; // 5 minutes
    protected $lockTTL = 5000; // milliseconds

    // 🔧 Redis Key Helpers
    private function zsetKey($subId, $type) { return "z:active_streams:{$subId}:{$type}"; }
    private function hashKey($subId, $type, $devId) { return "h:stream:{$subId}:{$type}:{$devId}"; }
    private function lockKey($subId, $type) { return "lock:subscription:{$subId}:{$type}"; }

    private function acquireLock($key, $ttl) {
        $token = Str::random(16);
        return Redis::set($key, $token, 'NX', 'PX', $ttl) ? $token : false;
    }

    private function releaseLock($key, $token) {
        if (Redis::get($key) === $token) Redis::del($key);
    }

    // 🧩 POST /api/stream/start
    public function start(Request $request)
    {

        $subscriptionId = $request->input('subscription_id');
        $deviceToken = $request->header('Device-Token');

        if (!$subscriptionId || !$deviceToken)
            return response()->json(['error'=>'Missing parameters'],400);

        $device = Devices::where('device_token', $deviceToken)->first();
        if (!$device)
            return response()->json(['error'=>'Invalid device'],404);

        $subscription = Subscription::find($subscriptionId);
        if (!$subscription || Carbon::parse($subscription->end_at)->isPast())
            return response()->json(['error'=>'Subscription expired'],403);

        $plan = Plan::find($subscription->plan_id);
        $type = strtolower($device->device_type);
        $limit = $plan->{'device_limit_'.$type} ?? 1;

        $lockKey = $this->lockKey($subscriptionId, $type);
        $token = $this->acquireLock($lockKey, $this->lockTTL);
        if (!$token)
            return response()->json(['error'=>'Try again'],429);

        try {
            $now = Carbon::now()->timestamp;
            $zsetKey = $this->zsetKey($subscriptionId, $type);

            // 🔄 Remove stale sessions
            $members = Redis::zrange($zsetKey, 0, -1, true);
            foreach ($members as $member => $score) {
                if ($now - $score > $this->streamTimeout) {
                    Redis::zrem($zsetKey, $member);
                    Redis::del($this->hashKey($subscriptionId, $type, $member));
                }
            }

            $activeCount = Redis::zcard($zsetKey);
            if ($activeCount >= $limit && !$device->is_owner_device) {
                return response()->json(['status'=>'error','message'=>'Device limit reached'],409);
            }

            // 🆕 Create new stream
            $streamToken = Str::uuid()->toString();
            $hashKey = $this->hashKey($subscriptionId, $type, $device->id);

            Redis::zadd($zsetKey, [$device->id => $now]);
            Redis::hmset($hashKey, [
                'stream_token' => $streamToken,
                'started_at' => $now,
                'last_ping' => $now,
                'status' => 'active'
            ]);

            ActiveStream::create([
                'subscription_id' => $subscriptionId,
                'device_id' => $device->id,
                'device_type' => $type,
                'stream_token' => $streamToken,
                'started_at' => now(),
                'last_ping' => now(),
                'status' => 'active',
            ]);

            return response()->json([
                'status' => 'success',
                'stream_token' => $streamToken,
                'max_quality' => $plan->quality,
                'remaining_slots' => max(0, $limit - $activeCount - 1)
            ]);

        } finally {
            $this->releaseLock($lockKey, $token);
        }
    }

    // 🧭 POST /api/stream/ping
    public function ping(Request $request)
    {
        $deviceToken = $request->header('Device-Token');
        $streamToken = $request->input('stream_token');

        $device = Devices::where('device_token', $deviceToken)->first();
        if (!$device)
            return response()->json(['error'=>'Invalid device'],404);

        $subscription = Subscription::where('user_id', $device->user_id)->first();
        if (!$subscription)
            return response()->json(['error'=>'Subscription not found'],404);

        $hashKey = $this->hashKey($subscription->id, $device->device_type, $device->id);
        $zsetKey = $this->zsetKey($subscription->id, $device->device_type);

        $storedToken = Redis::hget($hashKey, 'stream_token');
        if ($storedToken !== $streamToken)
            return response()->json(['error'=>'Invalid token'],401);

        $now = Carbon::now()->timestamp;
        Redis::hset($hashKey, 'last_ping', $now);
        Redis::zadd($zsetKey, [$device->id => $now]);

        StreamEvent::create([
            'subscription_id' => $subscription->id,
            'device_id' => $device->id,
            'event_type' => 'ping',
            'event_data' => ['ts' => $now],
        ]);

        return response()->json(['status'=>'success']);
    }

    // 🧹 POST /api/stream/stop
    public function stop(Request $request)
    {
        $deviceToken = $request->header('Device-Token');
        $streamToken = $request->input('stream_token');

        $device = Devices::where('device_token', $deviceToken)->first();
        if (!$device)
            return response()->json(['error'=>'Invalid device'],404);

        $subscription = Subscription::where('user_id', $device->user_id)->first();
        if (!$subscription)
            return response()->json(['error'=>'Subscription not found'],404);

        $hashKey = $this->hashKey($subscription->id, $device->device_type, $device->id);
        $zsetKey = $this->zsetKey($subscription->id, $device->device_type);

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

        return response()->json(['status'=>'success']);
    }

    // 🔁 POST /api/subscription/renew
    public function renew(Request $request)
    {
        $subId = $request->input('subscription_id');
        $renewedBy = $request->input('renewed_by');
        if (!$subId)
            return response()->json(['error'=>'Missing subscription_id'],400);

        $subscription = Subscription::find($subId);
        if (!$subscription)
            return response()->json(['error'=>'Invalid subscription'],404);

        $plan = Plan::find($subscription->plan_id);

        // Extend subscription
        $newEnd = Carbon::parse($subscription->end_at)->addDays($plan->duration_days);
        $subscription->update(['end_at' => $newEnd]);

        $kept = []; 
        $kicked = [];

        foreach (['mobile', 'browser', 'tv'] as $type) {
            $lockKey = $this->lockKey($subId, $type);
            $token = $this->acquireLock($lockKey, $this->lockTTL);
            if (!$token) continue;

            try {
                $zsetKey = $this->zsetKey($subId, $type);
                $limit = $plan->{'device_limit_'.$type};
                $devices = Redis::zrevrange($zsetKey, 0, -1);

                $keepList = [];
                $owner = Devices::where('is_owner_device', true)
                    ->where('device_type', $type)
                    ->where('user_id', $subscription->user_id)
                    ->first();

                if ($owner) $keepList[] = $owner->id;

                foreach ($devices as $d) {
                    if (!in_array($d, $keepList) && count($keepList) < $limit) {
                        $keepList[] = $d;
                    }
                }

                foreach ($devices as $d) {
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
            'event_data' => ['kept' => $kept, 'kicked' => $kicked],
        ]);

        return response()->json([
            'status' => 'success',
            'kept_devices' => $kept,
            'kicked_devices' => $kicked,
        ]);
    }
}