<?php

namespace App\Http\Controllers;

use App\Http\Controllers\New\MovieController;
use App\Models\MovieModel;
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
    public $hlsFolderController;

    public function __construct(HlsFolderController $hlsFolderController, MovieController $movieController)
    {
        $this->hlsFolderController = $hlsFolderController;
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
        $platform = $request->input('platform');

        // 🔥 Detect PPV
        $isPPV = false;
        if ($movieId) {
            $movie = MovieModel::where('id', $movieId)->first();
            $isPPV = $movie && $movie->isPayPerView == 1;
        }

        // ✅ Allow no subscription for PPV
        if ((!$subscriptionId && !$isPPV) || !$deviceToken || !$userId) {
            return response()->json([
                'status' => 'error',
                'title' => 'Missing Information',
                'message' => 'Some required details are missing. Please try again or restart the app.'
            ], 400);
        }

        // 1️⃣ Device check
        $deviceQuery = Devices::where('device_token', $deviceToken)
            ->where('user_id', $userId);

        if (!$isPPV) {
            $deviceQuery->where('subscription_id', $subscriptionId);
        }

        $device = $deviceQuery->first();

        if (!$device) {
            return response()->json([
                'status' => 'error',
                'title' => 'Device Not Recognized',
                'message' => 'We couldn’t verify this device or your device has no subscription. Please sign in again or contact support if the issue continues.'
            ], 404);
        }

        // 🔥 Default values for PPV
        $subscription = null;
        $plan = null;
        $limit = 1;
        $type = strtolower(trim((string) $device->device_type));

        // =========================
        // ✅ SUBSCRIPTION FLOW ONLY
        // =========================
        if (!$isPPV) {

            // 2️⃣ Subscription check
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

            // 3️⃣ Plan check
            $plan = Plan::find($subscription->plan_id);

            if (!$plan) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Plan Information Unavailable',
                    'message' => 'We’re unable to retrieve your subscription plan right now. Please try again later.'
                ], 500);
            }

            $planType = strtolower(trim((string) $plan->device_type));

            if ($planType !== $type) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Invalid Plan for This Device',
                    'message' => 'Your current subscription does not support this device type.'
                ], 403);
            }

            $limit = $plan->device_limit ?? 1;
        }

        $streamToken = null;
        $currentActiveSeats = 0;
        $remainingSlots = 0;

        DB::beginTransaction();

        try {

            // =========================
            // ✅ SUBSCRIPTION STREAM LOGIC ONLY
            // =========================
            if (!$isPPV) {

                // 4️⃣ Cleanup stale streams
                $timeout = now()->subSeconds($this->streamTimeout);

                $staleStreams = ActiveStream::where('subscription_id', $subscriptionId)
                    ->where('device_type', $type)
                    ->where('last_ping', '<', $timeout)
                    ->get();

                foreach ($staleStreams as $stream) {
                    ActiveStream::where('id', $stream->id)
                        ->update(['status' => 'stopped']);
                }

                // 5️⃣ DB SEAT COUNT
                $dbActiveCount = Devices::where('subscription_id', $subscription->id)
                    ->where('user_id', $subscription->user_id)
                    ->where('device_type', $type)
                    ->where('status', 'active')
                    ->count();

                // 6️⃣ Device status
                $dbStatus = strtolower(trim((string) $device->status));

                if ($dbStatus === '') {
                    $dbStatus = 'inactive';
                }

                if (!in_array($dbStatus, ['active', 'inactive', 'blocked'], true)) {
                    return response()->json([
                        'status' => 'error',
                        'title' => 'Device Status Error',
                        'message' => 'This device is in an invalid state. Please sign in again.'
                    ], 409);
                }

                if ($dbStatus === 'blocked') {
                    return response()->json([
                        'status' => 'error',
                        'title' => 'Device Blocked',
                        'message' => 'This device has been blocked due to subscription renewal. Please verify your account or contact support for assistance.'
                    ], 403);
                }

                // 7️⃣ Enforce device limit
                if ($dbStatus === 'inactive' && $dbActiveCount >= $limit) {
                    return response()->json([
                        'status' => 'error',
                        'title' => 'Device Limit Reached',
                        'message' => 'You have reached the maximum number of devices allowed for your plan.'
                    ], 409);
                }

                // 8️⃣ Claim seat
                if ($dbStatus === 'inactive') {
                    $device->update(['status' => 'active']);
                    $dbActiveCount++;
                }

                $currentActiveSeats = $dbActiveCount;
                $remainingSlots = max(0, $limit - $currentActiveSeats);
            }

            // 9️⃣ Start stream (COMMON for both)
            $streamToken = Str::uuid()->toString();

            ActiveStream::updateOrCreate(
                [
                    'subscription_id' => $isPPV ? null : $subscriptionId,
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

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'title' => 'Stream Error',
                'message' => 'Unable to start stream. Please try again.'
            ], 500);
        }

        // 🔟 Movie links (UNCHANGED)
        $movieLinks = null;

        if ($movieId) {

            $req = new Request();
            $req->merge(['type' => $movieType]);

            $movieResponse = $this->movieController->getLink($req, $movieId);
            $movieData = $movieResponse->getData(true);

            if (($movieData['status'] ?? null) === 'success') {

                $links = $movieData['links'] ?? [];
                $title = $movieData['title'] ?? null;

                if ($platform === 'ios') {

                    $hlsUrl = $links['url'] ?? null;

                    if ($hlsUrl) {
                        $fakeReq = new Request();
                        $fakeReq->merge(['url' => $hlsUrl]);

                        $hlsResponse = $this->hlsFolderController->check($fakeReq);
                        $hlsData = $hlsResponse->getData(true);

                        $streamUrl = $hlsData['data']['stream_url'] ?? null;

                        $movieLinks = [
                            'title' => $title,
                            'links' => $streamUrl
                        ];
                    }

                } else {

                    $dashUrl = $links['url'] ?? null;

                    if ($dashUrl) {

                        $mpdUrl = $this->resolveMpdUrl($dashUrl)['url'];

                        $movieLinks = [
                            'title' => $title,
                            'links' => $mpdUrl
                        ];
                    }
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'stream_token' => $streamToken,
            'max_quality' => $plan->quality ?? 'FULL_HD', // 🔥 safe for PPV
            'current_active' => $currentActiveSeats,
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

    private function resolveMpdUrl(string $raw): array
    {
        // Case 1: Plain MPD URL
        if (Str::contains($raw, 'http') && Str::contains($raw, 'mpd')) {
            return [
                'url' => $raw,
                'source' => 'plaintext'
            ];
        }

        // Try decrypt
        $rawParam = str_replace(' ', '+', $raw);

        $shaKey = 'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a';

        // AES-256 key
        $decryptionKey = hash('sha256', $shaKey, true);

        // ---- Flexible Base64 decode ----
        $b64 = strtr($rawParam, '-_', '+/');
        $pad = strlen($b64) % 4;

        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $data = @base64_decode($b64, true);

        if ($data === false || strlen($data) < 17) {
            throw new \Exception('Invalid encrypted payload.');
        }

        // Extract IV + ciphertext
        $iv = substr($data, 0, 16);
        $cipherText = substr($data, 16);

        if (strlen($iv) !== 16 || $cipherText === '') {
            throw new \Exception('Corrupt encrypted payload.');
        }

        $decryptedMessage = openssl_decrypt(
            $cipherText,
            'aes-256-cbc',
            $decryptionKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decryptedMessage === false) {
            throw new \Exception('Decryption failed.');
        }

        // Normalize
        $result = trim(str_replace(["\r", "\n"], '', $decryptedMessage));

        $maybeUrl = filter_var($result, FILTER_VALIDATE_URL)
            ? $result
            : urldecode($result);

        if (stripos($maybeUrl, '.mpd') === false) {
            throw new \Exception('Decrypted URL is not an MPD manifest.');
        }

        return [
            'url' => $maybeUrl,
            'source' => 'decrypted'
        ];
    }
}
