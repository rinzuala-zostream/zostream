<?php

namespace App\Http\Controllers;

use App\Http\Controllers\New\MovieController;
use App\Models\MovieModel;
use App\Models\New\Episode;
use App\Models\New\PaymentHistory;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\New\Plan;
use App\Models\New\Subscription;
use App\Models\New\Devices;
use App\Models\New\ActiveStream;
use App\Models\New\StreamEvent;
use Log;

class NewStreamController extends Controller
{
    protected $streamTimeout = 500; // 8 minutes 20 seconds
    protected $lockTTL = 30000; // milliseconds

    public $movieController;
    public $hlsFolderController;
    public $watchPositionController;

    public function __construct(HlsFolderController $hlsFolderController, MovieController $movieController, WatchPositionController $watchPositionController)
    {
        $this->hlsFolderController = $hlsFolderController;
        $this->movieController = $movieController;
        $this->watchPositionController = $watchPositionController;
    }

    // 🧩 Start streaming

    public function start(Request $request)
    {
        $rawSubscriptionId = $request->input('subscription_id');
        $subscriptionId = filled($rawSubscriptionId) && (int) $rawSubscriptionId > 0
            ? (int) $rawSubscriptionId
            : null;
        $deviceToken = $request->header('Device-Token');
        $movieId = $request->input('movie_id');
        $seasonId = $request->input('season_id'); //This is optional and only used for rent whole episodes of season
        $movieType = $request->input('type');
        $contentType = $movieType ? strtolower(trim((string) $movieType)) : null;
        $contentId = filled($movieId) && is_numeric($movieId) ? (int) $movieId : null;
        $userId = $request->input('user_id');
        $platform = strtolower(trim((string) $request->input('platform')));

        $isPPV = false;
        $requiresSubscription = false;
        $movie = null;

        if ($movieId) {
            if ($movieType === 'movie') {
                $movie = MovieModel::where('id', $movieId)->first();
            } elseif ($movieType === 'episode') {
                $movie = Episode::where('id', $movieId)->first();
            }

            $isPPV = (bool) ($movie?->isPayPerView ?? false);
            $requiresSubscription = (bool) ($movie?->isPremium ?? false) && !$isPPV;

        }

        if (!$deviceToken || !$userId) {
            return response()->json([
                'status' => 'error',
                'title' => 'Missing Information',
                'message' => 'Some required details are missing. Please try again or restart the app.'
            ], 400);
        }

        // 🔥 Default values for PPV
        $subscription = null;
        $plan = null;
        $limit = 1;
        $type = strtolower(trim((string) $request->input('device_type', '')));

        // =========================
        // ✅ SUBSCRIPTION FLOW ONLY
        // =========================
        if ($requiresSubscription) {

            // 2️⃣ Subscription check
            $subscription = Subscription::find($subscriptionId);

            if (!$subscription) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Subscription Not Found',
                    'message' => 'We could not find an active subscription for your account. Please subscribe to continue watching.'
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
            $requestedType = $type;

            if ($requestedType && $planType !== $requestedType) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Invalid Plan for This Device',
                    'message' => 'Your current subscription does not support this device type.'
                ], 403);
            }

            $limit = $plan->device_limit ?? 1;
            $type = $planType;
        }

        // 1️⃣ Device check
        $deviceQuery = Devices::where('device_token', $deviceToken)
            ->where('user_id', $userId);

        if ($requiresSubscription) {
            $deviceQuery
                ->where('subscription_id', $subscriptionId)
                ->where('device_type', $type);
        }

        $device = $deviceQuery->first();

        if (!$device && $requiresSubscription) {
            $existingDevice = Devices::where('device_token', $deviceToken)
                ->where('user_id', $userId)
                ->where('device_type', $type)
                ->first();

            if ($existingDevice) {
                $nextStatus = strtolower(trim((string) $existingDevice->status)) === 'blocked'
                    ? 'inactive'
                    : ($existingDevice->status ?: 'inactive');

                $existingDevice->update([
                    'subscription_id' => $subscriptionId,
                    'last_activity' => now(),
                    'status' => $nextStatus,
                ]);

                $device = $existingDevice->fresh();
            }
        }

        if (!$device) {
            Log::warning('Start stream device not recognized', [
                'request' => $request->all(),
                'device_token' => $deviceToken,
                'subscription_id' => $subscriptionId,
                'requires_subscription' => $requiresSubscription,
                'movie_id' => $movieId,
                'season_id' => $seasonId,
                'type' => $movieType,
                'user_id' => $userId,
                'platform' => $platform,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'status' => 'error',
                'title' => 'Device Not Recognized',
                'message' => 'We couldn’t verify this device or your device has no subscription. Please sign in again or contact support if the issue continues.'
            ], 404);
        }

        if (!$type) {
            $type = strtolower(trim((string) $device->device_type));
        }

        if ($isPPV) {
            $baseQuery = PaymentHistory::where('user_id', $userId)
                ->where('status', 'success')
                ->where('expiry_date', '>', now())
                ->where('device_type', $device->device_type)
                ->where('app_payment_type', 'ppv'); // if you store this

            $rental = null;

            if ($seasonId && $movieType === 'episode') {
                // First check if whole season is rented
                $rental = (clone $baseQuery)
                    ->where('movie_id', $seasonId)
                    ->latest('payment_date')
                    ->latest('created_at')
                    ->first();

                // If not, check individual episode/movie
                if (!$rental) {
                    $rental = (clone $baseQuery)
                        ->where('movie_id', $movieId)
                        ->latest('payment_date')
                        ->latest('created_at')
                        ->first();
                }
            } else {
                // Check direct movie/episode rental
                $rental = (clone $baseQuery)
                    ->where('movie_id', $movieId)
                    ->latest('payment_date')
                    ->latest('created_at')
                    ->first();
            }

            if (!$rental) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Access Denied',
                    'message' => 'You do not have access to this content. Please rent it to start streaming.'
                ], 403);
            }

            if (!$device || !$device->is_owner_device) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Permission Denied',
                    'message' => 'Only the owner device can start a PPV stream.'
                ], 403);
            }

            if (!$this->isPpvRentalAllowedOnDevice($rental, $device, $deviceToken)) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'PPV Device Locked',
                    'message' => 'This rental is only available on the device that purchased it. Please rent this content on this device to start streaming.'
                ], 403);
            }
        }

        $streamToken = null;
        $currentActiveSeats = 0;
        $remainingSlots = 0;

        DB::beginTransaction();

        try {

            // =========================
            // ✅ SUBSCRIPTION STREAM LOGIC ONLY
            // =========================
            if ($requiresSubscription) {

                // 4️⃣ Cleanup stale streams
                $timeout = now()->subSeconds($this->streamTimeout);

                $staleStreams = ActiveStream::where('subscription_id', $subscriptionId)
                    ->where('device_type', $type)
                    ->where('status', 'active')
                    ->where('last_ping', '<', $timeout)
                    ->get();

                foreach ($staleStreams as $stream) {
                    ActiveStream::where('id', $stream->id)
                        ->update(['status' => 'stopped']);
                }

                $staleDeviceIds = $staleStreams
                    ->pluck('device_id')
                    ->filter()
                    ->unique()
                    ->values();

                if ($staleDeviceIds->isNotEmpty()) {
                    Devices::whereIn('id', $staleDeviceIds)
                        ->where('subscription_id', $subscriptionId)
                        ->where('user_id', $subscription->user_id)
                        ->where('device_type', $type)
                        ->where('status', 'active')
                        ->update([
                            'status' => 'inactive',
                            'last_activity' => now(),
                        ]);

                    $device->refresh();
                }

                $revokedDeviceIds = ActiveStream::query()
                    ->join('n_devices', 'n_active_streams.device_id', '=', 'n_devices.id')
                    ->leftJoin('session_tokens', function ($join) use ($subscription) {
                        $join->on('session_tokens.device_id', '=', 'n_devices.device_token')
                            ->where('session_tokens.user_id', '=', $subscription->user_id)
                            ->where('session_tokens.refresh_expires_at', '>', now());
                    })
                    ->where('n_active_streams.subscription_id', $subscriptionId)
                    ->where('n_active_streams.device_type', $type)
                    ->where('n_active_streams.status', 'active')
                    ->where('n_active_streams.last_ping', '>=', $timeout)
                    ->where('n_devices.user_id', $subscription->user_id)
                    ->whereNull('session_tokens.id')
                    ->pluck('n_active_streams.device_id')
                    ->filter()
                    ->unique()
                    ->values();

                if ($revokedDeviceIds->isNotEmpty()) {
                    ActiveStream::where('subscription_id', $subscriptionId)
                        ->whereIn('device_id', $revokedDeviceIds)
                        ->where('status', 'active')
                        ->update([
                            'status' => 'stopped',
                            'last_ping' => now(),
                        ]);

                    Devices::whereIn('id', $revokedDeviceIds)
                        ->where('status', 'active')
                        ->update([
                            'status' => 'inactive',
                            'last_activity' => now(),
                        ]);

                    $device->refresh();
                }

                // 5️⃣ Count actual streaming seats, not remembered login devices.
                $activeDeviceIds = ActiveStream::where('subscription_id', $subscriptionId)
                    ->where('device_type', $type)
                    ->where('status', 'active')
                    ->where('last_ping', '>=', $timeout)
                    ->lockForUpdate()
                    ->pluck('device_id')
                    ->filter()
                    ->unique()
                    ->values();

                $dbActiveCount = $activeDeviceIds->count();
                $currentDeviceHasActiveStream = $activeDeviceIds->contains((int) $device->id);

                // 6️⃣ Device status
                $dbStatus = strtolower(trim((string) $device->status));

                if ($dbStatus === '') {
                    $dbStatus = 'inactive';
                }

                if (!in_array($dbStatus, ['active', 'inactive', 'blocked'], true)) {
                    DB::rollBack();

                    return response()->json([
                        'status' => 'error',
                        'title' => 'Device Status Error',
                        'message' => 'This device is in an invalid state. Please sign in again.'
                    ], 409);
                }

                if ($dbStatus === 'blocked') {
                    $device->update([
                        'status' => 'inactive',
                        'subscription_id' => $subscriptionId,
                        'last_activity' => now(),
                    ]);
                    $device->refresh();
                    $dbStatus = 'inactive';
                }

                // 7️⃣ Enforce device limit
                if (!$currentDeviceHasActiveStream && $dbActiveCount >= $limit) {
                    DB::rollBack();

                    return response()->json([
                        'status' => 'error',
                        'title' => 'Device Limit Reached',
                        'message' => 'You have reached the maximum number of devices allowed for your plan.'
                    ], 409);
                }

                // 8️⃣ Claim seat
                if ($dbStatus === 'inactive') {
                    $device->update(['status' => 'active']);
                }

                if (!$currentDeviceHasActiveStream) {
                    $dbActiveCount++;
                }

                $currentActiveSeats = $dbActiveCount;
                $remainingSlots = max(0, $limit - $currentActiveSeats);
            }

            // 9️⃣ Start stream (COMMON for both)
            $streamKey = [
                'subscription_id' => $requiresSubscription ? $subscriptionId : null,
                'device_id' => $device->id
            ];

            $existingStream = ActiveStream::where($streamKey)
                ->lockForUpdate()
                ->first();

            // Every playback attempt gets a fresh token. A delayed stop request from
            // an older player can then no longer stop the current attempt.
            $streamToken = Str::uuid()->toString();

            $streamValues = [
                'device_type' => $type,
                'content_type' => $contentType,
                'content_id' => $contentId,
                'stream_token' => $streamToken,
                'started_at' => now(),
                'last_ping' => now(),
                'status' => 'active'
            ];

            if ($existingStream) {
                $existingStream->update($streamValues);
            } else {
                ActiveStream::create(array_merge($streamKey, $streamValues));
            }

            DB::commit();

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'title' => 'Stream Error',
                'message' => 'Unable to start stream. Please try again.',
                'error' => $e->getMessage(),
            ], 500);
        }

        // 🔟 Movie links (UNCHANGED)
        $movieLinks = null;
        $watchPosition = 0;

        if ($movieId) {
            $req = new Request();
            $req->merge(['type' => $movieType]);

            $movieResponse = $this->movieController->getLink($req, $movieId);
            $movieData = $movieResponse->getData(true);

            if (($movieData['status'] ?? null) === 'success') {
                $links = $movieData['links'] ?? [];
                $title = $movieData['title'] ?? null;

                $url = null;

                // movie format: links => ['url' => '...']
                if (isset($links['url']) && !empty($links['url'])) {
                    $url = $links['url'];
                }

                // episode format: links => [ ['url' => '...'], ... ]
                if (!$url && is_array($links) && !empty($links)) {
                    foreach ($links as $item) {
                        if (!empty($item['url'])) {
                            $url = $item['url'];
                            break;
                        }
                    }
                }

                if ($url) {
                    if ($platform === 'ios') {
                        $streamUrl = str_contains(strtolower($url), 'm3u8') ? $url : null;

                        if (!$streamUrl) {
                            $fakeReq = Request::create('', 'GET', ['url' => $url]);

                            $hlsResponse = $this->hlsFolderController->check($fakeReq);
                            $hlsData = $hlsResponse->getData(true);

                            $streamUrl = $hlsData['data']['stream_url'] ?? null;
                        }

                        if ($streamUrl) {
                            $movieLinks = [
                                'title' => $title,
                                'links' => $streamUrl
                            ];
                        }
                    } else {
                        $mpdUrl = $this->resolveMpdUrl($url)['url'] ?? null;

                        if ($mpdUrl) {
                            $movieLinks = [
                                'title' => $title,
                                'links' => $mpdUrl
                            ];
                        }
                    }
                }

                $watchRequest = Request::create('', 'GET', [
                    'userId' => $userId,
                    'movieId' => $movieId,
                    'isAgeRestricted' => 'false',
                ]);

                $watchResponse = $this->watchPositionController->getWatchPosition($watchRequest);

                if ($watchResponse && method_exists($watchResponse, 'getContent')) {
                    $watchData = json_decode($watchResponse->getContent(), true);

                    if (is_array($watchData) && ($watchData['status'] ?? null) === 'success') {
                        $watchPosition = $watchData['watchPosition'] ?? 0;
                    }
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'stream_token' => $streamToken,
            'max_quality' => $plan->quality ?? 'FULL_HD',
            'current_active' => $currentActiveSeats,
            'device_limit' => $limit,
            'watch_position' => $watchPosition,
            'remaining_slots' => $remainingSlots,
            'movie_links' => $movieLinks
        ]);
    }

    // 🔁 Ping stream
    public function ping(Request $request)
    {
        $deviceToken = $request->header('Device-Token');
        $streamToken = $request->input('stream_token');
        $subscriptionId = $request->input('subscription_id');
        $movieId = $request->input('movie_id');
        $movieType = $request->input('type');
        $contentType = $movieType ? strtolower(trim((string) $movieType)) : null;
        $contentId = filled($movieId) && is_numeric($movieId) ? (int) $movieId : null;

        $isPPV = false;
        $requiresSubscription = false;
        $movie = null;

        if ($movieId) {
            if ($movieType === 'movie') {
                $movie = MovieModel::where('id', $movieId)->first();
            } elseif ($movieType === 'episode') {
                $movie = Episode::where('id', $movieId)->first();
            }

            $isPPV = (bool) ($movie?->isPayPerView ?? false);
            $requiresSubscription = (bool) ($movie?->isPremium ?? false) && !$isPPV;
        }

        // 1️⃣ Device check
        $deviceQuery = Devices::where('device_token', $deviceToken)
            ->where('user_id', $request->input('auth_user_id'));

        if ($requiresSubscription) {
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

        if ($device->status === 'blocked') {
            $device->update([
                'status' => 'active',
                'last_activity' => now(),
            ]);
            $device->refresh();
        }

        // 🔹 Subscription check ONLY for premium

        if ($requiresSubscription) {
            $subscription = Subscription::find($subscriptionId);

            if (!$subscription) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Subscription Unavailable',
                    'message' => 'We could not find an active subscription for your account. Please check your subscription status.',
                    'device' => $device
                ], 404);
            }
        }

        // 🔹 Stream check
        $streamQuery = ActiveStream::where('stream_token', $streamToken)
            ->where('status', 'active');

        if ($requiresSubscription) {
            $streamQuery->where('device_id', $device->id)
                ->where('subscription_id', $subscriptionId);
        }

        $stream = $streamQuery->first();

        if (!$stream && $contentType && $contentId) {
            // A superseded start response or delayed stop can leave the player with
            // an old token. Recover only the authenticated device's same content.
            $recoveryQuery = ActiveStream::where('device_id', $device->id)
                ->where('content_type', $contentType)
                ->where('content_id', $contentId);

            if ($requiresSubscription) {
                $recoveryQuery->where('subscription_id', $subscriptionId);
            }

            $stream = $recoveryQuery->latest('last_ping')->first();

            if ($stream) {
                $stream->update([
                    'status' => 'active',
                    'last_ping' => now(),
                ]);

                Log::info('Stream ping session recovered', [
                    'requested_stream_token' => $streamToken,
                    'recovered_stream_token' => $stream->stream_token,
                    'device_id' => $device->id,
                    'subscription_id' => $subscriptionId,
                    'content_type' => $contentType,
                    'content_id' => $contentId,
                ]);
            }
        }

        if (!$stream) {
            $activeDeviceStream = ActiveStream::where('device_id', $device->id)
                ->where('status', 'active')
                ->latest('last_ping')
                ->first();

            Log::warning('Stream ping session expired', [
                'stream_token' => $streamToken,
                'device_token' => $deviceToken,
                'device_id' => $device->id,
                'subscription_id' => $subscriptionId,
                'movie_id' => $movieId,
                'type' => $movieType,
                'content_type' => $contentType,
                'content_id' => $contentId,
                'requires_subscription' => $requiresSubscription,
                'active_device_stream_token' => $activeDeviceStream?->stream_token,
                'active_device_stream_content_type' => $activeDeviceStream?->content_type,
                'active_device_stream_content_id' => $activeDeviceStream?->content_id,
                'active_device_stream_last_ping' => $activeDeviceStream?->last_ping,
            ]);

            return response()->json([
                'status' => 'error',
                'title' => 'Session Expired',
                'message' => 'Your streaming session has expired or is invalid. Please restart playback to continue watching.'
            ], 403);
        }

        $stream->update([
            'last_ping' => now()
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Streaming session is active.',
            'stream_token' => $stream->stream_token,
        ]);
    }

    // 🧹 Stop stream
    public function stop(Request $request)
    {
        try {
            // ✅ Validate input first
            $validated = $request->validate([
                'stream_token' => 'required|string',
                'watch_position' => 'nullable|integer|min:0',
                'content_type' => 'required|string|in:movie,episode',
                'movie_id' => 'required',
                'user_id' => 'required',
                'duration' => 'nullable|integer|min:0',
            ]);

            $streamToken = $validated['stream_token'];
            $watchPosition = $validated['watch_position'] ?? 0;
            $contentType = $validated['content_type'];
            $movieId = $validated['movie_id'];
            $userId = $validated['user_id'];
            $duration = $validated['duration'] ?? 0;

            // ✅ Find stream
            $stream = ActiveStream::where('stream_token', $streamToken)->first();

            if (!$stream) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Session Not Found',
                    'message' => 'This streaming session could not be found or has already ended.'
                ], 404);
            }

            $shouldIncrementViews = strtolower((string) $stream->status) !== 'stopped';
            $incrementContentType = $stream->content_type ?: $contentType;
            $incrementContentId = $stream->content_id ?: $movieId;

            // ✅ Update safely
            $stream->update([
                'status' => 'stopped',
                'last_ping' => now()
            ]);

            if ($shouldIncrementViews) {
                $this->incrementContentViews($incrementContentType, $incrementContentId);
            }

            // ✅ Call watch position safely
            $watchData = [];

            try {
                $fakeRequest = Request::create('', 'POST', [
                    'movie_id' => $movieId,
                    'position' => $watchPosition,
                    'user_id' => $userId,
                    'duration' => $duration,
                    'movie_type' => $contentType,
                ]);

                $watchResponse = $this->watchPositionController->save($fakeRequest);

                if ($watchResponse && method_exists($watchResponse, 'getContent')) {
                    $watchData = json_decode($watchResponse->getContent(), true) ?? [];
                }

            } catch (\Throwable $e) {
                // ❗ Watch position failed but do not break stop API
                Log::warning('Watch position save failed', [
                    'stream_token' => $streamToken,
                    'error' => $e->getMessage()
                ]);
            }

            $watchMessage = $watchData['message'] ?? '';

            return response()->json([
                'status' => 'success',
                'message' => 'Streaming stopped.' . ($watchMessage ? ' ' . $watchMessage : ''),
                'watch_position_response' => $watchData,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Invalid request data.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {

            Log::error('Stop stream failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong while stopping the stream.',
            ], 500);
        }
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

            $kept = [];
            $reset = [];
            $currentDeviceId = (int) $currentDevice->id;

            // Devices for this user + type
            $devices = Devices::where('device_type', $deviceType)
                ->where('user_id', $userId)
                ->get();

            foreach ($devices as $device) {

                $deviceId = $device->id;
                $nextStatus = (int) $deviceId === $currentDeviceId
                    ? 'active'
                    : (strtolower(trim((string) $device->status)) === 'blocked'
                        ? 'inactive'
                        : ($device->status ?: 'inactive'));

                // ensure device linked to subscription
                $device->update([
                    'subscription_id' => $subId,
                    'status' => $nextStatus,
                    'last_activity' => now(),
                ]);

                if ($nextStatus === 'active') {
                    $kept[] = $deviceId;
                } elseif (strtolower(trim((string) $device->status)) === 'blocked') {
                    $reset[] = $deviceId;
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
                'reset' => $reset
            ],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Your subscription has been renewed successfully.',
            'device_type' => $deviceType,
            'owner_device' => $kept,
            'reset_devices' => $reset
        ]);
    }

    private function isPpvRentalAllowedOnDevice(PaymentHistory $rental, Devices $device, string $deviceToken): bool
    {
        $meta = is_array($rental->meta) ? $rental->meta : [];
        $rentalDeviceToken = trim((string) ($meta['device_token'] ?? ''));
        $rentalDeviceId = isset($meta['device_id']) ? (int) $meta['device_id'] : null;

        if ($rentalDeviceToken !== '') {
            return hash_equals($rentalDeviceToken, $deviceToken);
        }

        if ($rentalDeviceId && (int) $device->id === $rentalDeviceId) {
            return true;
        }

        // Older PPV rows were not device-bound. Let the owner device claim the
        // rental once, then lock all future playback to this exact device.
        if ($device->is_owner_device) {
            $rental->update([
                'meta' => array_merge($meta, [
                    'device_token' => $deviceToken,
                    'device_id' => $device->id,
                    'device_type' => $device->device_type,
                    'bound_at' => now()->toDateTimeString(),
                ]),
            ]);

            return true;
        }

        return false;
    }

    private function incrementContentViews(?string $contentType, $contentId): void
    {
        if (!$contentType || !$contentId) {
            return;
        }

        try {
            $modelClass = match (strtolower(trim((string) $contentType))) {
                'movie' => MovieModel::class,
                'episode' => Episode::class,
                default => null,
            };

            if (!$modelClass) {
                return;
            }

            $model = new $modelClass;

            if (!Schema::hasColumn($model->getTable(), 'views')) {
                return;
            }

            $primaryKey = $model->getKeyName();

            $updated = $modelClass::query()
                ->where('id', $contentId)
                ->increment('views', 1);

            if (!$updated && $primaryKey && $primaryKey !== 'id') {
                $modelClass::query()
                    ->where($primaryKey, $contentId)
                    ->increment('views', 1);
            }
        } catch (\Throwable $e) {
            Log::warning('Stream view increment failed', [
                'content_type' => $contentType,
                'content_id' => $contentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveMpdUrl(string $raw): array
    {
        $trimmedRaw = trim($raw);

        if ($this->isDirectPlayableStreamUrl($trimmedRaw)) {
            return [
                'url' => $trimmedRaw,
                'source' => 'direct'
            ];
        }

        // Case 1: Plain MPD URL
        if (Str::contains($trimmedRaw, 'http') && Str::contains($trimmedRaw, 'mpd')) {
            return [
                'url' => $trimmedRaw,
                'source' => 'plaintext'
            ];
        }

        // Try decrypt
        $rawParam = str_replace('%2B', '+', $trimmedRaw); // 🔥 fix
        $rawParam = str_replace(' ', '+', $rawParam);

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

    private function isDirectPlayableStreamUrl(string $url): bool
    {
        $lower = strtolower($url);

        return filter_var($url, FILTER_VALIDATE_URL)
            && (
                str_contains($lower, 'm3u8')
                || str_contains($lower, '.mpd')
                || str_contains($lower, '.mp4')
                || str_contains($lower, '.m4v')
                || str_contains($lower, '.mp3')
                || str_contains($lower, '.aac')
                || str_contains($lower, 'webrtc')
                || str_contains($lower, 'whep')
                || str_contains($lower, 'live')
            );
    }
}
