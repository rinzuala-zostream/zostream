<?php

namespace App\Http\Controllers;

use App\Http\Controllers\New\MovieController;
use DB;
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
    protected $streamTimeout = 500; // 5 minutes
    protected $lockTTL = 30000; // milliseconds

    public $movieController;

    public function __construct(MovieController $movieController)
    {
        $this->movieController = $movieController;
    }

    // 🧩 Start streaming

    public function start(Request $request)
    {
        $subscriptionId = $request->input('subscription_id');
        $deviceToken = $request->header('Device-Token');
        $movieId = $request->input('movie_id');
        $movieType = $request->input('type');
        $userId = $request->input('user_id');

        if (!$subscriptionId || !$deviceToken || !$userId) {
            return response()->json([
                'status' => 'error',
                'title' => 'Missing Information',
                'message' => 'Some required details are missing. Please try again or restart the app.'
            ], 400);
        }

        $device = Devices::where('device_token', $deviceToken)
            ->where('subscription_id', $subscriptionId)
            ->where('user_id', $userId)
            ->first();

        if (!$device) {
            return response()->json([
                'status' => 'error',
                'title' => 'Device Not Recognized',
                'message' => 'We couldn’t verify this device or your device has no subscription. Please sign in again or contact support if the issue continues.'
            ], 404);
        }

        $subscription = Subscription::find($subscriptionId);
        if (!$subscription) {
            return response()->json([
                'status' => 'error',
                'title' => 'Subscription Not Found',
                'message' => 'We could not find an active subscription for your account. Please check your subscription status.'
            ], 404);
        }

        if (Carbon::parse($subscription->end_at)->isPast() || !$subscription->is_active) {
            return response()->json([
                'status' => 'error',
                'title' => 'Subscription Expired',
                'message' => 'Your subscription has expired. Please renew your plan to continue watching.'
            ], 403);
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
        $planType = strtolower(trim($plan->device_type));

        if ($planType !== $type) {
            return response()->json([
                'status' => 'error',
                'title' => 'Invalid Plan for This Device',
                'message' => 'Your current subscription does not support this device type.'
            ], 403);
        }

        $limit = $plan->device_limit ?? 1;

        DB::beginTransaction();

        try {

            // 🔥 Cleanup stale streams
            ActiveStream::where('subscription_id', $subscriptionId)
                ->where('device_type', $type)
                ->where('last_ping', '<', now()->subSeconds($this->streamTimeout))
                ->update(['status' => 'stopped']);

            // free devices
            Devices::where('subscription_id', $subscriptionId)
                ->where('device_type', $type)
                ->where('status', 'active')
                ->whereDoesntHave('activeStream', function ($q) {
                    $q->where('status', 'active');
                })
                ->update(['status' => 'inactive']);

            $dbActiveCount = Devices::where('subscription_id', $subscriptionId)
                ->where('device_type', $type)
                ->where('status', 'active')
                ->count();

            $dbStatus = strtolower($device->status);

            if ($dbStatus === 'blocked') {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Access Restricted',
                    'message' => 'This device is currently restricted. Please verify your account or contact support for assistance.'
                ], 403);
            }

            if ($dbStatus === 'inactive' && $dbActiveCount >= $limit) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Device Limit Reached',
                    'message' => 'You have reached the maximum number of devices allowed for your plan.'
                ], 409);
            }

            if ($dbStatus === 'inactive') {
                $device->update(['status' => 'active']);
                $dbActiveCount++;
            }

            $streamToken = Str::uuid()->toString();

            ActiveStream::updateOrCreate(
                [
                    'subscription_id' => $subscriptionId,
                    'device_id' => $device->id
                ],
                [
                    'device_type' => $type,
                    'stream_token' => $streamToken,
                    'started_at' => now(),
                    'last_ping' => now(),
                    'status' => 'active'
                ]
            );

            DB::commit();

        } catch (\Throwable $e) {

            DB::rollBack();
            throw $e;
        }

        $remainingSlots = max(0, $limit - $dbActiveCount);

        $movieLinks = null;
        if ($movieId) {
            $request = new Request(); // create empty request 
            $request->merge(['type' => $movieType]); // optional if needed 
            $movieResponse = $this->movieController->getLink($request, $movieId);
            $movieData = $movieResponse->getData(true);
            if (($movieData['status'] ?? null) === 'success') {
                $movieLinks = ['title' => $movieData['title'], 'links' => $movieData['links']];
            }
        }

        return response()->json([
            'status' => 'success',
            'stream_token' => $streamToken,
            'max_quality' => $plan->quality,
            'current_active' => $dbActiveCount,
            'device_limit' => $limit,
            'remaining_slots' => $remainingSlots,
            'movie_links' => $movieLinks
        ]);
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

        if ($device->status === 'blocked') {
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

        $stream = ActiveStream::where('subscription_id', $device->subscription_id)
            ->where('device_id', $device->id)
            ->where('stream_token', $streamToken)
            ->where('status', 'active')
            ->first();

        if (!$stream) {
            return response()->json([
                'status' => 'error',
                'title' => 'Session Expired',
                'message' => 'Your streaming session has expired or is invalid. Please restart playback to continue watching.'
            ], 401);
        }

        $stream->update([
            'last_ping' => now()
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

        $stream = ActiveStream::where('subscription_id', $device->subscription_id)
            ->where('device_id', $device->id)
            ->where('stream_token', $streamToken)
            ->first();

        if (!$stream) {
            return response()->json([
                'status' => 'error',
                'title' => 'Session Not Found',
                'message' => 'This streaming session could not be found or has already ended. Please restart playback if needed.'
            ], 401);
        }

        $stream->update([
            'status' => 'stopped',
            'last_ping' => now()
        ]);

        $device->update([
            'status' => 'inactive'
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Streaming stopped.'
        ]);
    }

    // 🔁 Renew subscription
    public function renew(Request $request)
    {
        $subId = $request->input('subscription_id');
        $userId = $request->input('user_id');
        $userDeviceToken = $request->input('device_id');
        $deviceType = strtolower(trim($request->input('device_type')));

        if (!$subId || !$userId || !$deviceType || !$userDeviceToken) {
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

        // Verify owner device
        $currentDevice = Devices::where('device_token', $userDeviceToken)
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

        DB::beginTransaction();

        try {

            // Extend subscription
            $newEnd = Carbon::parse($subscription->end_at)->addDays($plan->duration_days);

            $subscription->update([
                'end_at' => $newEnd
            ]);

            $kept = [];
            $blocked = [];

            // Devices for this user + type
            $devices = Devices::where('device_type', $deviceType)
                ->where('user_id', $userId)
                ->get();

            $owner = $devices->where('is_owner_device', true)->first();

            foreach ($devices as $device) {

                $deviceId = $device->id;

                // ensure device linked to subscription
                if ($device->subscription_id !== $subId) {
                    $device->update(['subscription_id' => $subId]);
                }

                if ($owner && $deviceId === $owner->id) {

                    ActiveStream::updateOrCreate(
                        [
                            'subscription_id' => $subId,
                            'device_id' => $deviceId
                        ],
                        [
                            'device_type' => $deviceType,
                            'stream_token' => Str::uuid()->toString(),
                            'status' => 'active',
                            'last_ping' => now()
                        ]
                    );
                    Devices::where('id', $deviceId)->update(['status' => 'active']);
                    $kept[] = $deviceId;

                } else {

                    ActiveStream::where('subscription_id', $subId)
                        ->where('device_id', $deviceId)
                        ->update(['status' => 'stopped']);

                    Devices::where('id', $deviceId)->update(['status' => 'blocked']);
                    $blocked[] = $deviceId;
                }
            }

            DB::commit();

        } catch (\Throwable $e) {

            DB::rollBack();
            throw $e;
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
