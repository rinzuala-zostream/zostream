<?php

namespace App\Http\Controllers;

use App\Http\Controllers\New\MovieController;
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

    public $movieController;

    public function __construct(MovieController $movieController)
    {
        $this->movieController = $movieController;
    }

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
        $movieId = $request->input('movie_id'); // optional
        $userId = $request->input('user_id'); // optional

        if (!$subscriptionId || !$deviceToken) {
            return response()->json([
                'status' => 'error',
                'title' => 'Missing Information',
                'message' => 'Some required details are missing. Please try again or restart the app.'
            ], 400);
        }

        // 1) Device check
        $device = Devices::where('device_token', $deviceToken)
        ->where('user_id', $userId)->first();
        if (!$device) {
            return response()->json([
                'status' => 'error',
                'title' => 'Device Not Recognized',
                'message' => 'We couldn’t verify this device. Please sign in again or contact support if the issue continues.'
            ], 404);
        }

        // 2) Subscription check
        $subscription = Subscription::find($subscriptionId);
        if (!$subscription) {
            return response()->json([
                'status' => 'error',
                'title' => 'Subscription Not Found',
                'message' => 'We could not find an active subscription for your account. Please check your subscription status.'
            ], 404);
        }

        if (Carbon::parse($subscription->end_at)->isPast()) {
            return response()->json([
                'status' => 'error',
                'title' => 'Subscription Expired',
                'message' => 'Your subscription has expired. Please renew your plan to continue watching.'
            ], 403);
        }

        // 3) Plan check
        $plan = Plan::find($subscription->plan_id);
        if (!$plan) {
            return response()->json([
                'status' => 'error',
                'title' => 'Plan Information Unavailable',
                'message' => 'We’re unable to retrieve your subscription plan right now. Please try again later.'
            ], 500);
        }

        $type = strtolower(trim($device->device_type));

        /**
         * IMPORTANT:
         * Plan is now device-specific.
         * So we must verify that the subscription plan
         * matches the device type.
         */
        if ($plan->device_type !== $type) {
            return response()->json([
                'status' => 'error',
                'title' => 'Invalid Plan for This Device',
                'message' => 'Your current subscription does not support this device type.'
            ], 403);
        }

        $limit = $plan->device_limit ?? 1;

        // 4) Acquire Redis lock
        $lockKey = $this->lockKey($subscriptionId, $type);
        $token = $this->acquireLock($lockKey, $this->lockTTL);

        if (!$token) {
            return response()->json([
                'status' => 'error',
                'title' => 'Please Try Again',
                'message' => 'We’re processing another request right now. Please try again in a moment.'
            ], 429);
        }

        try {
            $now = Carbon::now()->timestamp;
            $zsetKey = $this->zsetKey($subscriptionId, $type);

            // 5) Cleanup stale Redis sessions (THIS DOES NOT FREE DB SLOT)
            $members = Redis::zrange($zsetKey, 0, -1, true) ?: [];
            foreach ($members as $memberId => $score) {
                if ($now - (int) $score > $this->streamTimeout) {
                    Redis::zrem($zsetKey, $memberId);
                    Redis::del($this->hashKey($subscriptionId, $type, $memberId));

                    ActiveStream::where('subscription_id', $subscriptionId)
                        ->where('device_id', $memberId)
                        ->update(['status' => 'stopped']);
                }
            }

            // 6) DB SEAT COUNT (source of truth for plan limit)
            // IMPORTANT: This must count devices that are "active" in DB (owner included)
            $dbActiveCount = Devices::where('user_id', $subscription->user_id)
                ->where('device_type', $type)
                ->where('status', 'active')
                ->count();

            // 7) Device current status (DB first; Redis is session only)
            $hashKey = $this->hashKey($subscriptionId, $type, $device->id);
            $dbStatus = $device->status; // 'active' | 'inactive' | 'blocked'

            if ($dbStatus === 'blocked') {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Access Restricted',
                    'message' => 'This device is currently restricted. Please verify your account or contact support for assistance.'
                ], 403);
            }

            // 8) Enforce limit using DB seats
            // If device is inactive AND seats are full -> cannot start
            if ($dbStatus === 'inactive' && $dbActiveCount >= $limit) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Device Limit Reached',
                    'message' => 'You have reached the maximum number of devices allowed for your plan.'
                ], 409);
            }

            // 9) If inactive and seat available -> claim seat (set DB active)
            if ($dbStatus === 'inactive') {
                $device->update(['status' => 'active']);
                $dbActiveCount++; // seat claimed now
            }

            // 10) Start Redis session (live streaming)
            $streamToken = Str::uuid()->toString();

            Redis::zadd($zsetKey, [$device->id => $now]); // refresh or add
            Redis::hmset($hashKey, [
                'stream_token' => $streamToken,
                'started_at' => $now,
                'last_ping' => $now,
                'status' => 'active',
                'device_name' => $device->device_name,
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

            // 11) Optional movie links
            $movieLinks = null;
            if ($movieId) {
                $movieResponse = $this->movieController->getLink($movieId);
                $movieData = $movieResponse->getData(true);

                if (($movieData['status'] ?? null) === 'success') {
                    $movieLinks = [
                        'title' => $movieData['title'],
                        'links' => $movieData['links']
                    ];
                }
            }

            // 12) Return counts based on DB seats (not Redis)
            $currentActiveSeats = $dbActiveCount; // DB active devices
            $remainingSlots = max(0, $limit - $currentActiveSeats);

            return response()->json([
                'status' => 'success',
                'stream_token' => $streamToken,
                'max_quality' => $plan->quality,
                'current_active' => $currentActiveSeats,   // ✅ DB seats (owner included)
                'device_limit' => $limit,
                'remaining_slots' => $remainingSlots,
                'movie_links' => $movieLinks
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
                'title' => 'Device Not Recognized',
                'message' => 'We couldn’t verify this device. Please sign in again or contact support if the issue continues.'
            ], 404);
        }

        $hashKey = $this->hashKey($device->subscription_id, strtolower($device->device_type), $device->id);
        $status = Redis::hget($hashKey, 'status');

        if ($status === 'blocked') {
            return response()->json([
                'status' => 'error',
                'title' => 'Device Blocked',
                'message' => 'This device has been blocked due to subscription renewal. Please verify your account or contact support for assistance.'
            ], 403);
        }

        $subscription = Subscription::find($device->subscription_id);
        if (!$subscription) {
            return response()->json([
                'status' => 'error',
                'title' => 'Subscription Unavailable',
                'message' => 'We could not find an active subscription for your account. Please check your subscription status.'
            ], 404);
        }

        $plan = Plan::find($subscription->plan_id);
        if (!$plan) {
            return response()->json([
                'status' => 'error',
                'title' => 'Plan Information Unavailable',
                'message' => 'We’re unable to retrieve your subscription plan right now. Please try again later.'
            ], 500);
        }

        $type = strtolower(trim($device->device_type));
        $zsetKey = $this->zsetKey($subscription->id, $type);
        $hashKey = $this->hashKey($subscription->id, $type, $device->id);

        $storedToken = Redis::hget($hashKey, 'stream_token');
        if ($storedToken !== $streamToken) {
            return response()->json([
                'status' => 'error',
                'title' => 'Session Expired',
                'message' => 'Your streaming session has expired. Please restart playback to continue watching.'
            ], 401);
        }

        $status = Redis::hget($hashKey, 'status');
        if ($status !== 'active') {
            return response()->json([
                'status' => 'error',
                'title' => 'Streaming Paused',
                'message' => 'Your device is no longer active for streaming. Please restart playback to continue.'
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

        return response()->json([
            'status' => 'success',
            'message' => 'Streaming session is active.'
        ]);
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
                'title' => 'Device Not Recognized',
                'message' => 'We couldn’t verify this device. Please sign in again or contact support if the issue continues.'
            ], 404);
        }

        $subscription = Subscription::find($device->subscription_id);
        if (!$subscription) {
            return response()->json([
                'status' => 'error',
                'title' => 'Device Not Recognized',
                'message' => 'We couldn’t verify this device. Please sign in again or contact support if the issue continues.'
            ], 404);
        }

        $type = strtolower(trim($device->device_type));
        $hashKey = $this->hashKey($subscription->id, $type, $device->id);
        $zsetKey = $this->zsetKey($subscription->id, $type);

        $storedToken = Redis::hget($hashKey, 'stream_token');
        if ($storedToken !== $streamToken) {
            return response()->json([
                'status' => 'error',
                'title' => 'Session Not Found',
                'message' => 'This streaming session could not be found or has already ended. Please restart playback if needed.'
            ], 401);
        }

        Redis::hset($hashKey, 'status', 'stop');
        Redis::zrem($zsetKey, $device->id);

        ActiveStream::where('device_id', $device->id)
            ->where('subscription_id', $subscription->id)
            ->update(['status' => 'stopped', 'last_ping' => now()]);

        StreamEvent::create([
            'subscription_id' => $subscription->id,
            'device_id' => $device->id,
            'event_type' => 'stop',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Streaming has been stopped successfully.'
        ]);
    }

    // 🔁 Renew subscription
    public function renew(Request $request)
    {
        $subId = $request->input('subscription_id');
        $userId = $request->input('user_id');
        $userDeviceId = $request->input('device_id');
        $deviceType = strtolower(trim($request->input('device_type'))); // mobile | browser | tv

        if (!$subId || !$userId || !$deviceType || !$userDeviceId) {
            return response()->json([
                'status' => 'error',
                'title' => 'Missing Information',
                'message' => 'Some required details are missing. Please try again.'
            ], 400);
        }

        if (!in_array($deviceType, ['mobile', 'browser', 'tv'], true)) {
            return response()->json([
                'status' => 'error',
                'title' => 'Invalid Device Type',
                'message' => 'Please provide a valid device type (mobile, browser, or tv).'
            ], 422);
        }

        $subscription = Subscription::find($subId);
        if (!$subscription) {
            return response()->json([
                'status' => 'error',
                'title' => 'Subscription Not Found',
                'message' => 'We could not find the subscription you’re trying to renew. Please check your details and try again.'
            ], 404);
        }

        $plan = Plan::find($subscription->plan_id);
        if (!$plan) {
            return response()->json([
                'status' => 'error',
                'title' => 'Plan Information Unavailable',
                'message' => 'We’re unable to retrieve your subscription plan right now. Please try again later.'
            ], 500);
        }

        // 🔐 Verify the requesting device belongs to this user and device type
        $currentDevice = Devices::where('device_token', $userDeviceId)
            ->where('user_id', $userId)
            ->first();

        if (!$currentDevice) {
            return response()->json([
                'status' => 'error',
                'title' => 'Unauthorized Device',
                'message' => 'This device is not authorized to renew the subscription.'
            ], 403);
        }

        if (!$currentDevice->is_owner_device) {
            return response()->json([
                'status' => 'error',
                'title' => 'Permission Denied',
                'message' => 'Only the owner device can renew the subscription.'
            ], 403);
        }

        // Extend subscription
        $newEnd = Carbon::parse($subscription->end_at)->addDays($plan->duration_days);
        $subscription->update(['end_at' => $newEnd]);

        $lockKey = $this->lockKey($subId, $deviceType);
        $token = $this->acquireLock($lockKey, $this->lockTTL);
        if (!$token) {
            return response()->json([
                'status' => 'error',
                'title' => 'Please Try Again',
                'message' => 'We’re processing another request. Please try again shortly.'
            ], 429);
        }

        $kept = [];
        $blocked = [];

        try {
            $zsetKey = $this->zsetKey($subId, $deviceType);

            // 🔎 Only devices for this USER + DEVICE TYPE (subscription set/updated below)
            $devices = Devices::where('device_type', $deviceType)
                ->where('user_id', $userId)
                ->get();


            // Owner device for this device type
            $owner = $devices->where('is_owner_device', true)->first();

            foreach ($devices as $device) {
                $deviceId = $device->id;
                $hashKey = $this->hashKey($subId, $deviceType, $deviceId);

                // 🔗 Ensure device is linked to this subscription
                if ($device->subscription_id !== $subId) {
                    $device->update(['subscription_id' => $subId]);
                }

                if ($owner && $deviceId === $owner->id) {
                    // ✅ Keep owner active
                    Redis::hset($hashKey, 'status', 'active');

                    $existingToken = Redis::hget($hashKey, 'stream_token');

                    ActiveStream::updateOrCreate(
                        ['subscription_id' => $subId, 'device_id' => $deviceId],
                        [
                            'device_type' => $deviceType,
                            'stream_token' => $existingToken ?: Str::uuid()->toString(),
                            'status' => 'active',
                            'last_ping' => now(),
                        ]
                    );

                    Devices::where('id', $deviceId)->update(['status' => 'active']);
                    $kept[] = $deviceId;

                } else {
                    // 🚫 Block other devices of this type for this user
                    Redis::hset($hashKey, 'status', 'blocked');
                    Redis::zrem($zsetKey, $deviceId);

                    ActiveStream::where('subscription_id', $subId)
                        ->where('device_id', $deviceId)
                        ->update(['status' => 'stopped']);

                    Devices::where('id', $deviceId)->update(['status' => 'blocked']);
                    $blocked[] = $deviceId;
                }
            }

        } finally {
            $this->releaseLock($lockKey, $token);
        }

        StreamEvent::create([
            'subscription_id' => $subId,
            'event_type' => 'renew',
            'event_data' => [
                'device_type' => $deviceType,
                'owner' => $kept,
                'blocked' => $blocked
            ],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Your subscription has been renewed successfully.',
            'device_type' => $deviceType,
            'owner_device' => $kept,
            'blocked_devices' => $blocked
        ]);
    }
}