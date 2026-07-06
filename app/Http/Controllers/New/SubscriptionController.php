<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Http\Controllers\NewStreamController;
use App\Http\Controllers\RazorpayController;
use App\Models\UserModel;
use App\Models\New\Devices;
use App\Models\New\PaymentHistory;
use DB;
use Illuminate\Http\Request;
use App\Models\New\Subscription;
use App\Models\New\Plan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Exception;

class SubscriptionController extends Controller
{
    protected $razorpayController;
    protected $streamEventController;

    public function __construct(
        RazorpayController $razorpayController,
        NewStreamController $streamEventController

    ) {
        $this->razorpayController = $razorpayController;
        $this->streamEventController = $streamEventController;
    }
    /**
     * 📋 List all subscriptions (with filters and pagination)
     */
    public function index(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 15);
            $perPage = $perPage > 0 ? min($perPage, 100) : 15;
            $search = trim((string) $request->get('search', ''));
            $deviceType = strtolower(trim((string) $request->get('device_type', '')));
            $sortBy = (string) $request->get('sort_by', 'created_at');
            $sortDirection = strtolower((string) $request->get('sort_direction', 'desc')) === 'asc'
                ? 'asc'
                : 'desc';

            $allowedSorts = ['id', 'user_id', 'plan_id', 'start_at', 'end_at', 'created_at'];
            if (!in_array($sortBy, $allowedSorts, true)) {
                $sortBy = 'created_at';
            }

            $query = Subscription::with(['plan', 'devices']);

            if ($request->filled('is_active')) {
                $query->where('is_active', filter_var(
                    $request->get('is_active'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                ) ?? false);
            }

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('user_id', 'like', '%' . $search . '%')
                        ->orWhere('id', $search)
                        ->orWhere('plan_id', $search)
                        ->orWhereHas('plan', function ($planQuery) use ($search) {
                            $planQuery->where('name', 'like', '%' . $search . '%');
                        });
                });
            }

            if ($deviceType !== '') {
                if (!in_array($deviceType, ['mobile', 'browser', 'tv'], true)) {
                    return $this->respond([
                        'status' => 'error',
                        'message' => 'Invalid device type. Allowed: mobile, browser, tv'
                    ], 422);
                }

                $query->whereHas('plan', function ($q) use ($deviceType) {
                    $q->where('device_type', $deviceType);
                });
            }

            $query->orderBy($sortBy, $sortDirection);

            return $this->respond([
                'status' => 'success',
                'data' => $query->paginate($perPage)
            ]);

        } catch (\Exception $e) {
            Log::error('Subscription index error', [
                'payload' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return $this->respond([
                "status" => "error",
                "message" => "Failed to fetch subscriptions"
            ]);
        }
    }

    /**
     * Search subscriptions through both subscription data and the linked user.
     */
    public function searchSubscribers(Request $request)
    {
        try {
            $search = trim((string) $request->get('search', $request->get('q', '')));

            if ($search === '') {
                return $this->respond([
                    'status' => 'error',
                    'message' => 'Search query is required'
                ], 422);
            }

            if (mb_strlen($search) > 120) {
                return $this->respond([
                    'status' => 'error',
                    'message' => 'Search query may not be greater than 120 characters'
                ], 422);
            }

            $perPage = (int) $request->get('per_page', 15);
            $perPage = $perPage > 0 ? min($perPage, 100) : 15;
            $deviceType = strtolower(trim((string) $request->get('device_type', '')));
            $sortBy = (string) $request->get('sort_by', 'created_at');
            $sortDirection = strtolower((string) $request->get('sort_direction', 'desc')) === 'asc'
                ? 'asc'
                : 'desc';
            $allowedSorts = ['id', 'user_id', 'plan_id', 'start_at', 'end_at', 'created_at'];

            if (!in_array($sortBy, $allowedSorts, true)) {
                $sortBy = 'created_at';
            }

            if ($deviceType !== '' && !in_array($deviceType, ['mobile', 'browser', 'tv'], true)) {
                return $this->respond([
                    'status' => 'error',
                    'message' => 'Invalid device type. Allowed: mobile, browser, tv'
                ], 422);
            }

            $phone = preg_match('/^[\d\s()+-]+$/', $search)
                ? (preg_replace('/\D+/', '', $search) ?: '')
                : '';
            if (strlen($phone) >= 5) {
                $phone = substr($phone, -10);
            } else {
                $phone = '';
            }

            $query = Subscription::with([
                'plan',
                'devices',
                'user:num,uid,name,mail,call,auth_phone',
            ])->where(function ($subscriptionQuery) use ($search, $phone) {
                $subscriptionQuery
                    ->where('user_id', 'like', '%' . $search . '%')
                    ->orWhereHas('plan', function ($planQuery) use ($search) {
                        $planQuery->where('name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('user', function ($userQuery) use ($search, $phone) {
                        $userQuery->where(function ($userSearch) use ($search, $phone) {
                            $userSearch->where('uid', 'like', '%' . $search . '%')
                                ->orWhere('name', 'like', '%' . $search . '%')
                                ->orWhere('mail', 'like', '%' . $search . '%')
                                ->orWhere('call', 'like', '%' . $search . '%')
                                ->orWhere('auth_phone', 'like', '%' . $search . '%');

                            if ($phone !== '') {
                                $normalizedCall = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(`call`, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
                                $normalizedAuthPhone = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(`auth_phone`, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";

                                $userSearch->orWhereRaw("{$normalizedCall} LIKE ?", ['%' . $phone . '%'])
                                    ->orWhereRaw("{$normalizedAuthPhone} LIKE ?", ['%' . $phone . '%']);
                            }
                        });
                    });

                if (ctype_digit($search)) {
                    $subscriptionQuery->orWhere('id', $search)
                        ->orWhere('plan_id', $search);
                }
            });

            if ($request->filled('is_active')) {
                $query->where('is_active', filter_var(
                    $request->get('is_active'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                ) ?? false);
            }

            if ($deviceType !== '') {
                $query->whereHas('plan', function ($planQuery) use ($deviceType) {
                    $planQuery->where('device_type', $deviceType);
                });
            }

            return $this->respond([
                'status' => 'success',
                'data' => $query
                    ->orderBy($sortBy, $sortDirection)
                    ->paginate($perPage)
                    ->appends($request->query())
            ]);
        } catch (\Exception $e) {
            Log::error('Subscriber search error', [
                'payload' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return $this->respond([
                'status' => 'error',
                'message' => 'Failed to search subscribers'
            ], 500);
        }
    }

    public function getByDeviceType($device_type)
    {
        try {

            $plans = Plan::with([
                'features' => function ($q) {
                    $q->where('is_active', true)
                        ->orderBy('sort_order');
                }
            ])
                ->where('is_active', true)
                ->where('device_type', $device_type) // ✅ filter here
                ->orderByRaw("FIELD(name, 'Kar 1', 'Thla 1', 'Thla 4', 'Thla 6', 'Kum 1')")
                ->get()
                ->groupBy('name');

            $response = [];

            foreach ($plans as $planName => $planGroup) {

                $perDevicePrice = [];
                $deviceFeatures = [];

                foreach ($planGroup as $plan) {

                    $perDevicePrice[ucfirst($plan->device_type)] = (float) $plan->price;

                    $deviceFeatures[ucfirst($plan->device_type)] =
                        $plan->features->pluck('feature')->toArray();
                }

                $first = $planGroup->first();

                $response[] = [
                    "plan_id" => (int) $first->id,
                    "plan" => $planName,
                    "original_price" => (float) $planGroup->sum('price'),
                    "duration_days" => (int) $first->duration_days,
                    "per_device_price" => $perDevicePrice,
                    "per_device_features" => $deviceFeatures
                ];
            }

            return $this->respond([
                "status" => "success",
                "data" => $response
            ]);

        } catch (\Exception $e) {
            return $this->respond([
                "status" => "error",
                "message" => "Failed to fetch plans"
            ]);
        }
    }

    /**
     * 🔍 Show a single subscription with plan + devices
     */
    public function show($id)
    {
        try {
            $subscription = Subscription::with(['plan', 'devices', 'activeStreams'])
                ->find($id);

            if (!$subscription) {
                return $this->respond([
                    'status' => 'error',
                    'message' => 'Subscription not found'
                ], 404);
            }

            return $this->respond([
                'status' => 'success',
                'data' => $subscription
            ]);
        } catch (Exception $e) {
            Log::error('Subscription show error', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to retrieve subscription', $e);
        }
    }

    /**
     * 👤 Get all subscriptions by user ID (with plan + devices)
     */
    /**
     * 👤 Get all subscriptions by user ID (with optional device_type filter)
     */
    public function getByUser(Request $request, $userId)
    {
        try {

            $perPage = $request->get('per_page', 15);
            $deviceType = strtolower(trim($request->get('device_type')));

            $query = Subscription::with(['plan', 'devices'])
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->orderBy('created_at', 'desc');

            // ✅ If device_type provided → return single object
            if (!empty($deviceType)) {

                if (!in_array($deviceType, ['mobile', 'browser', 'tv'], true)) {
                    return $this->respond([
                        'status' => 'error',
                        'message' => 'Invalid device type. Allowed: mobile, browser, tv'
                    ], 422);
                }

                $subscription = $query->whereHas('plan', function ($q) use ($deviceType) {
                    $q->where('device_type', $deviceType);
                })->first(); // 👈 important

                if (!$subscription) {
                    return $this->respond([
                        'status' => 'error',
                        'message' => 'No active subscription found for this device type'
                    ], 404);
                }

                return response()->json(
                    array_merge($subscription->toArray(), [
                        'current_date' => $this->formattedCurrentDate(),
                    ]) // 👈 object
                );
            }

            // ✅ If no device_type → return paginated list
            $subscriptions = $query->paginate($perPage);

            if ($subscriptions->isEmpty()) {
                return $this->respond([
                    'status' => 'error',
                    'message' => 'No subscriptions found for this user'
                ], 404);
            }

            return $this->respond([
                'status' => 'success',
                'data' => $subscriptions
            ]);

        } catch (Exception $e) {

            Log::error('Subscription getByUser error', [
                'user_id' => $userId,
                'device_type' => $request->get('device_type'),
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to retrieve user subscriptions', $e);
        }
    }

    /**
     * ➕ Create a new subscription
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $razorpayKeyId = null;

            $request->validate([
                'user_id' => 'required|string|max:225',
                'plan_id' => 'nullable|integer|exists:n_plans,id',
                'amount' => 'nullable|numeric|min:0',
                'device_type' => 'nullable|string|max:50',
                'app_payment_type' => 'nullable|string|max:100',
                'payment_method' => 'nullable|string|max:100',
                'payment_gateway' => 'nullable|string|max:100',
                'transaction_id' => 'nullable|string|max:255',
                'currency' => 'nullable|string|max:10',
                'movie_id' => 'nullable|string|max:225', // 🔥 required for PPV
            ]);

            $paymentType = strtolower(trim($request->app_payment_type));
            $transactionId = $request->transaction_id;

            if (!$transactionId || trim($transactionId) === '') {

                // Create Razorpay order
                $fakeRequest = new Request([
                    'amount' => $request->amount ?? 0,
                    'currency' => $request->currency ?? 'INR',

                ]);

                $razorpayResponse = $this->razorpayController->createOrder($fakeRequest);

                $razorpayData = $razorpayResponse->getData(true);

                if (!$razorpayData['ok']) {

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Failed to create Razorpay order',
                        'error' => $razorpayData
                    ], 400);
                }

                $transactionId = $razorpayData['order']['id'];
                $razorpayKeyId = $razorpayData['key_id'] ?? null;
            }

            /*
            |--------------------------------------------------------------------------
            | 🎬 PPV FLOW
            |--------------------------------------------------------------------------
            */
            if ($paymentType === 'ppv') {

                if (!$request->movie_id) {
                    return $this->respond([
                        'status' => 'error',
                        'message' => 'movie_id is required for PPV'
                    ], 422);
                }

                $startAt = now();
                $endAt = $startAt->copy()->addDays(7); // default 7-day PPV

                $payment = PaymentHistory::create([
                    'subscription_id' => null,
                    'user_id' => $request->user_id,
                    'movie_id' => $request->movie_id, // 🔥 important
                    'device_type' => $request->device_type ?? 'mobile',
                    'app_payment_type' => 'ppv',
                    'amount' => $request->amount ?? 0,
                    'currency' => $request->currency ?? 'INR',
                    'payment_method' => $request->payment_method,
                    'payment_gateway' => $request->payment_gateway,
                    'transaction_id' => $transactionId,
                    'status' => 'pending',
                    'payment_type' => 'new',
                    'created_at' => now(),
                    'expiry_date' => $endAt,
                    'meta' => [
                        'device_token' => $request->device_id ?: $request->device_token,
                        'device_id' => null,
                        'device_type' => $request->device_type ?? 'mobile',
                    ],
                ]);

                DB::commit();

                return $this->respond([
                    'status' => 'success',
                    'message' => 'PPV payment created.',
                    'data' => array_merge($payment->toArray(), [
                        'key_id' => $razorpayKeyId,
                    ])
                ], 201);
            }

            /*
            |--------------------------------------------------------------------------
            | 📦 SUBSCRIPTION FLOW
            |--------------------------------------------------------------------------
            */

            $plan = Plan::find($request->plan_id);

            if (!$plan) {
                return $this->respond([
                    'status' => 'error',
                    'message' => 'Invalid plan ID'
                ], 404);
            }

            $startAt = now();
            $endAt = $startAt->copy()->addDays($plan->duration_days);
            $deviceToken = $request->device_id ?: $request->device_token;
            $deviceType = strtolower(trim((string) ($request->device_type ?? $plan->device_type)));
            $device = $deviceToken
                ? Devices::where('user_id', $request->user_id)
                    ->where('device_token', $deviceToken)
                    ->where('device_type', $deviceType)
                    ->first()
                : null;

            PaymentHistory::create([

                'user_id' => $request->user_id,
                'plan_id' => $plan->id,
                'app_payment_type' => $request->app_payment_type,
                'device_type' => $plan->device_type,
                'amount' => $plan->price,
                'currency' => $request->currency ?? 'INR',
                'payment_method' => $request->payment_method,
                'payment_gateway' => $request->payment_gateway,
                'transaction_id' => $transactionId,
                'status' => 'pending',
                'payment_type' => 'new',
                'payment_date' => now(),
                'expiry_date' => $endAt,
                'meta' => array_merge(
                    is_array($request->meta) ? $request->meta : [],
                    [
                        'device_token' => $deviceToken,
                        'device_id' => $device?->id,
                        'device_type' => $deviceType,
                    ]
                ),
            ]);

            DB::commit();

            return $this->respond([
                'status' => 'success',
                'message' => 'Subscription created. Payment pending.',
                'data' => array_merge(
                    ['user_id' => $request->user_id],
                    ['plan_id' => $plan->id],
                    ['start_at' => $startAt],
                    ['end_at' => $endAt],
                    ['is_active' => false],
                    ['transaction_id' => $transactionId],
                    ['key_id' => $razorpayKeyId],
                )
            ], 201);

        } catch (Exception $e) {

            DB::rollBack();

            Log::error('Subscription store error', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to create subscription: ' . $e->getMessage(), $e);
        }
    }

    /**
     * Create an active subscription and payment history for a user's selected plan.
     *
     * The device is resolved from user_id + the selected plan's device_type, then
     * linked to the newly created subscription.
     */
    public function createSubscriptionWithPayment(Request $request)
    {
        DB::beginTransaction();

        try {
            $request->merge([
                'plan_id' => $request->input('plan_id') ?? $request->input('selected_plan_id'),
            ]);

            $validated = $request->validate([
                'user_id' => 'required|string|max:225',
                'plan_id' => 'required|integer|exists:n_plans,id',
                'amount' => 'nullable|numeric|min:0',
                'currency' => 'nullable|string|max:10',
                'payment_method' => 'nullable|string|max:100',
                'payment_gateway' => 'nullable|string|max:100',
                'transaction_id' => 'nullable|string|max:255',
                'payment_type' => 'nullable|string|in:new,renew,upgrade,downgrade',
                'status' => 'nullable|string|in:pending,success,failed,refunded',
                'target_device_token' => 'nullable|string|max:255',
                'start_at' => 'nullable',
                'end_at' => 'nullable',
            ]);

            $resolvedUserId = $this->resolveUserIdFromUidOrPhone($validated['user_id']);

            if (!$resolvedUserId) {
                DB::rollBack();

                return $this->respond([
                    'status' => 'error',
                    'message' => 'User not found for the provided user id or phone number'
                ], 404);
            }

            $plan = Plan::where('id', $validated['plan_id'])
                ->where('is_active', true)
                ->first();

            if (!$plan) {
                DB::rollBack();

                return $this->respond([
                    'status' => 'error',
                    'message' => 'Invalid or inactive plan selected'
                ], 404);
            }

            $deviceQuery = Devices::where('user_id', $resolvedUserId)
                ->where('device_type', $plan->device_type);

            if (!empty($validated['target_device_token'])) {
                $deviceQuery->where('device_token', $validated['target_device_token']);
            } else {
                $deviceQuery->where('is_owner_device', 1);
            }

            $device = $deviceQuery->lockForUpdate()->first();

            if (!$device) {
                DB::rollBack();

                return $this->respond([
                    'status' => 'error',
                    'message' => 'No device found for this user and plan device type'
                ], 404);
            }

            $paymentStatus = $validated['status'] ?? 'success';
            $isActive = $paymentStatus === 'success';
            $manualStartAt = !empty($validated['start_at'])
                ? Carbon::parse($validated['start_at'])->startOfDay()
                : null;
            $manualEndAt = !empty($validated['end_at'])
                ? Carbon::parse($validated['end_at'])->endOfDay()
                : null;
            $startAt = $manualStartAt ?? now();

            if ($manualEndAt && $manualEndAt->lt($startAt)) {
                DB::rollBack();

                return $this->respond([
                    'status' => 'error',
                    'message' => 'End date cannot be earlier than start date'
                ], 422);
            }

            $subscription = $isActive
                ? Subscription::activeForUserAndDeviceType($resolvedUserId, $plan->device_type)
                    ->lockForUpdate()
                    ->first()
                : null;
            $endAt = $manualEndAt
                ?? ($manualStartAt
                    ? $manualStartAt->copy()->addDays($plan->duration_days)
                    : ($subscription && $subscription->end_at && $subscription->end_at->isFuture()
                        ? $subscription->end_at->copy()->addDays($plan->duration_days)
                        : $startAt->copy()->addDays($plan->duration_days)));

            if ($subscription) {
                $updates = [
                    'plan_id' => $plan->id,
                    'end_at' => $endAt,
                    'is_active' => true,
                    'renewed_by' => null,
                ];

                if ($manualStartAt || !$subscription->end_at || $subscription->end_at->isPast()) {
                    $updates['start_at'] = $startAt;
                }

                $subscription->update($updates);
            } else {
                $subscription = Subscription::create([
                    'user_id' => $resolvedUserId,
                    'plan_id' => $plan->id,
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'is_active' => $isActive,
                    'renewed_by' => null,
                ]);
            }

            $payment = PaymentHistory::create([
                'subscription_id' => $subscription->id,
                'user_id' => $resolvedUserId,
                'plan_id' => $plan->id,
                'device_type' => $plan->device_type,
                'app_payment_type' => 'subscription',
                'amount' => $validated['amount'] ?? $plan->price,
                'currency' => $validated['currency'] ?? 'INR',
                'payment_method' => $validated['payment_method'] ?? "manual",
                'payment_gateway' => $validated['payment_gateway'] ?? "manual",
                'transaction_id' => $validated['transaction_id'] ?? "manual",
                'status' => $paymentStatus,
                'payment_type' => $validated['payment_type'] ?? 'new',
                'payment_date' => now(),
                'expiry_date' => $endAt,
                'meta' => [
                    'identifier' => 'Manual subscription entry',
                    'device_token' => $device->device_token,
                ],
            ]);

            $device->update([
                'subscription_id' => $subscription->id,
                'last_activity' => now(),
                'status' => $isActive ? 'active' : ($device->status ?: 'inactive'),
            ]);

            $renewPayload = [
                'user_id' => $resolvedUserId,
                'device_id' => $device->device_token,
                'subscription_id' => $subscription->id,
                'device_type' => $plan->device_type
            ];

            Log::info('Subscription createSubscriptionWithPayment renew request', [
                'payload' => $renewPayload,
                'actual_device_id' => $device->id,
            ]);

            $fakeRequest = new Request($renewPayload);
            $renewResponse = $this->streamEventController->renew($fakeRequest);
            $renewData = [];

            if ($renewResponse && method_exists($renewResponse, 'getContent')) {
                $renewData = json_decode($renewResponse->getContent(), true) ?? [];
            }

            Log::info('Subscription createSubscriptionWithPayment renew response', [
                'status_code' => method_exists($renewResponse, 'getStatusCode')
                    ? $renewResponse->getStatusCode()
                    : null,
                'response' => $renewData,
            ]);

            DB::commit();
            return $this->respond([
                'status' => 'success',
                'message' => 'Subscription and payment history created successfully.',
                'data' => [
                    'requested_user_id' => $validated['user_id'],
                    'resolved_user_id' => $resolvedUserId,
                    'subscription' => $subscription->fresh('plan'),
                    'payment_history' => $payment,
                    'device' => $device->fresh(),
                    'renew_response' => $renewData,
                ],
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Subscription createSubscriptionWithPayment error', [
                'payload' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to create subscription with payment history', $e);
        }
    }

    private function resolveUserIdFromUidOrPhone(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $user = UserModel::where('uid', $value)->first();

        if ($user) {
            return $user->uid;
        }

        $user = UserModel::where('auth_phone', $value)
            ->orWhere('auth_phone', $value)
            ->first();

        return $user?->uid;
    }

    /**
     * 🔄 Update a subscription
     */
    public function update(Request $request, $id)
    {
        try {
            $subscription = Subscription::find($id);

            if (!$subscription) {
                return $this->respond([
                    'status' => 'error',
                    'message' => 'Subscription not found'
                ], 404);
            }

            $validated = $request->validate([
                'plan_id' => 'nullable|integer|exists:n_plans,id',
                'start_at' => 'nullable|date',
                'end_at' => 'nullable|date',
                'is_active' => 'nullable|boolean',
                'renewed_by' => 'nullable|string|max:225',
            ]);

            $subscription->update($validated);

            return $this->respond([
                'status' => 'success',
                'message' => 'Subscription updated successfully',
                'data' => $subscription
            ]);
        } catch (Exception $e) {
            Log::error('Subscription update error', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to update subscription', $e);
        }
    }

    /**
     * ❌ Delete a subscription
     */
    public function destroy($id)
    {
        try {
            $subscription = Subscription::find($id);

            if (!$subscription) {
                return $this->respond([
                    'status' => 'error',
                    'message' => 'Subscription not found'
                ], 404);
            }

            $subscription->delete();

            return $this->respond([
                'status' => 'success',
                'message' => 'Subscription deleted successfully'
            ]);
        } catch (Exception $e) {
            Log::error('Subscription destroy error', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to delete subscription', $e);
        }
    }

    /**
     * 🧩 Common JSON error handler
     */
    private function errorResponse(string $message, Exception $e, int $code = 500)
    {
        return $this->respond([
            'status' => 'error',
            'message' => $message,
            'error' => $e->getMessage(),
        ], $code);
    }

    private function respond(array $payload, int $status = 200)
    {
        return response()->json(array_merge([
            'current_date' => $this->formattedCurrentDate(),
        ], $payload), $status);
    }

    private function formattedCurrentDate(): string
    {
        return Carbon::now()->format('F j, Y');
    }
}
