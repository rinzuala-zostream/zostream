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

    // 🔐 Acquire Redis lock
    private function acquireLock($key, $ttl) {
        $token = Str::random(16);
        return Redis::set($key, $token, 'NX', 'PX', $ttl) ? $token : false;
    }

    // 🔓 Release Redis lock
    private function releaseLock($key, $token) {
        if (!$token) return;
        try {
            if (Redis::get($key) === $token) {
                Redis::del($key);
            }
        } catch (\Throwable $e) {
            \Log::warning("Failed to release lock {$key}: ".$e->getMessage());
        }
    }

    // 🧩 Start streaming
    public function start(Request $request)
    {
        $subscriptionId = $request->input('subscription_id');
        $deviceToken = $request->header('Device-Token');

        if (!$subscriptionId || !$deviceToken) {
            return response()->json(['error'=>'Missing parameters'],400);
        }

        $device = Devices::where('device_token', $deviceToken)->first();
        if (!$device) return response()->json(['error'=>'Invalid device'],404);

        $subscription = Subscription::find($subscriptionId);
        if (!$subscription || Carbon::parse($subscription->end_at)->isPast()) {
            return response()->json(['error'=>'Subscription expired'],403);
        }

        $plan = Plan::find($subscription->plan_id);
        if (!$plan) return response()->json(['error'=>'Plan not found'],500);

        $type = strtolower(trim($device->device_type));

        $limit = match ($type) {
            'mobile' => $plan->device_limit_mobile ?? 1,
            'browser'=> $plan->device_limit_browser ?? 1,
            'tv'     => $plan->device_limit_tv ?? 1,
            default  => 1,
        };

        $lockKey = $this->lockKey($subscriptionId, $type);
        $token = $this->acquireLock($lockKey, $this->lockTTL);
        if (!$token) return response()->json(['error'=>'Try again'],429);

        try {
            $now = Carbon::now()->timestamp;
            $zsetKey = $this->zsetKey($subscriptionId, $type);

            // Remove stale sessions
            $members = Redis::zrange($zsetKey, 0, -1, true) ?: [];
            foreach ($members as $member => $score) {
                if ($now - (int)$score > $this->streamTimeout) {
                    Redis::zrem($zsetKey, $member);
                    Redis::del($this->hashKey($subscriptionId, $type, $member));
                }
            }

            // Owner ID
            $ownerId = Devices::where('user_id', $subscription->user_id)
                ->where('device_type', $type)
                ->where('is_owner_device', true)
                ->value('id') ?? null;

            $activeMembers = Redis::zrange($zsetKey, 0, -1);
            $nonOwnerActiveCount = count(array_filter($activeMembers, fn($id) => $id != $ownerId));

            $isOwner = (bool) $device->is_owner_device;

            if ($nonOwnerActiveCount >= $limit && !$isOwner) {
                return response()->json(['status'=>'error','message'=>'Device limit reached'],409);
            }

            // Start stream
            $streamToken = Str::uuid()->toString();
            $hashKey = $this->hashKey($subscriptionId, $type, $device->id);

            Redis::zadd($zsetKey, [$device->id => $now]);
            try {
                Redis::hmset($hashKey, [
                    'stream_token' => $streamToken,
                    'started_at' => $now,
                    'last_ping' => $now,
                    'status' => 'active'
                ]);
            } catch (\Throwable $e) {
                Redis::hset($hashKey, 'stream_token', $streamToken);
                Redis::hset($hashKey, 'started_at', $now);
                Redis::hset($hashKey, 'last_ping', $now);
                Redis::hset($hashKey, 'status', 'active');
            }

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

            return response()->json([
                'status'=>'success',
                'stream_token'=>$streamToken,
                'max_quality'=>$plan->quality,
                'current_active'=> $nonOwnerActiveCount + ($isOwner ? 0 : 1) + ($isOwner ? 1 : 0),
                'device_limit'=>$limit,
                'remaining_slots'=> max(0, $limit - $nonOwnerActiveCount - ($isOwner ? 0 : 1))
            ]);

        } finally {
            $this->releaseLock($lockKey, $token);
        }
    }

    // 🧭 Ping stream
    public function ping(Request $request)
    {
        $deviceToken = $request->header('Device-Token');
        $streamToken = $request->input('stream_token');

        $device = Devices::where('device_token', $deviceToken)->first();
        if (!$device) return response()->json(['error'=>'Invalid device'],404);

        $subscription = Subscription::find($device->subscription_id);
        if (!$subscription) return response()->json(['error'=>'Subscription not found'],404);

        $type = strtolower(trim($device->device_type));
        $hashKey = $this->hashKey($subscription->id, $type, $device->id);
        $zsetKey = $this->zsetKey($subscription->id, $type);

        $storedToken = Redis::hget($hashKey, 'stream_token');
        if ($storedToken !== $streamToken) return response()->json(['error'=>'Invalid token'],401);

        $now = Carbon::now()->timestamp;
        Redis::hset($hashKey, 'last_ping', $now);
        Redis::zadd($zsetKey, [$device->id => $now]);

        StreamEvent::create([
            'subscription_id'=>$subscription->id,
            'device_id'=>$device->id,
            'event_type'=>'ping',
            'event_data'=>['ts'=>$now]
        ]);

        return response()->json(['status'=>'success']);
    }

    // 🧹 Stop stream
    public function stop(Request $request)
    {
        $deviceToken = $request->header('Device-Token');
        $streamToken = $request->input('stream_token');

        $device = Devices::where('device_token', $deviceToken)->first();
        if (!$device) return response()->json(['error'=>'Invalid device'],404);

        $subscription = Subscription::find($device->subscription_id);
        if (!$subscription) return response()->json(['error'=>'Subscription not found'],404);

        $type = strtolower(trim($device->device_type));
        $hashKey = $this->hashKey($subscription->id, $type, $device->id);
        $zsetKey = $this->zsetKey($subscription->id, $type);

        Redis::zrem($zsetKey, $device->id);
        Redis::del($hashKey);

        ActiveStream::where('device_id',$device->id)
            ->where('subscription_id',$subscription->id)
            ->update(['status'=>'stopped','last_ping'=>now()]);

        StreamEvent::create([
            'subscription_id'=>$subscription->id,
            'device_id'=>$device->id,
            'event_type'=>'stop'
        ]);

        return response()->json(['status'=>'success']);
    }

    // 🔁 Renew subscription
    public function renew(Request $request)
    {
        $subId = $request->input('subscription_id');
        if (!$subId) return response()->json(['error'=>'Missing subscription_id'],400);

        $subscription = Subscription::find($subId);
        if (!$subscription) return response()->json(['error'=>'Invalid subscription'],404);

        $plan = Plan::find($subscription->plan_id);
        if (!$plan) return response()->json(['error'=>'Plan not found'],500);

        // Extend subscription
        $newEnd = Carbon::parse($subscription->end_at)->addDays($plan->duration_days);
        $subscription->update(['end_at'=>$newEnd]);

        $kept = [];
        $kicked = [];

        foreach (['mobile','browser','tv'] as $type) {
            $lockKey = $this->lockKey($subId, $type);
            $token = $this->acquireLock($lockKey,$this->lockTTL);
            if (!$token) continue;

            try {
                $zsetKey = $this->zsetKey($subId,$type);
                $limit = match($type){
                    'mobile'=>$plan->device_limit_mobile ?? 1,
                    'browser'=>$plan->device_limit_browser ?? 1,
                    'tv'=>$plan->device_limit_tv ?? 1,
                    default=>1,
                };

                $devices = Redis::zrevrange($zsetKey,0,-1) ?: [];

                $keepList = [];
                $owner = Devices::where('is_owner_device',true)
                    ->where('device_type',$type)
                    ->where('user_id',$subscription->user_id)
                    ->first();

                if($owner) $keepList[] = $owner->id;

                foreach($devices as $d){
                    if(!in_array($d,$keepList) && count($keepList)<$limit){
                        $keepList[] = $d;
                    }
                }

                foreach($devices as $d){
                    if(!in_array($d,$keepList)){
                        Redis::zrem($zsetKey,$d);
                        Redis::del($this->hashKey($subId,$type,$d));
                        $kicked[] = $d;
                    }
                }

                $kept = array_merge($kept,$keepList);

            } finally {
                $this->releaseLock($lockKey,$token);
            }
        }

        StreamEvent::create([
            'subscription_id'=>$subId,
            'event_type'=>'renew',
            'event_data'=>['kept'=>$kept,'kicked'=>$kicked]
        ]);

        return response()->json([
            'status'=>'success',
            'kept_devices'=>$kept,
            'kicked_devices'=>$kicked
        ]);
    }
}