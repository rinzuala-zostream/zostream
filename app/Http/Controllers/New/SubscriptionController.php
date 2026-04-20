<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Http\Controllers\RazorpayController;
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

    public function __construct(
        RazorpayController $razorpayController,

    ) {
        $this->razorpayController = $razorpayController;
    }
    /**
     * 📋 List all subscriptions (with filters and pagination)
     */
    public function index(Request $request)
    {
        try {

            $plans = Plan::with([
                'features' => function ($q) {
                    $q->where('is_active', true)
                        ->orderBy('sort_order');
                }
            ])
                ->where('is_active', true)
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
                    'meta' => null,
                ]);

                DB::commit();

                return $this->respond([
                    'status' => 'success',
                    'message' => 'PPV payment created.',
                    'data' => $payment
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
                'meta' => null,
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
            ]);

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

            $device = Devices::where('user_id', $validated['user_id'])
                ->where('device_type', $plan->device_type)
                ->where('is_owner_device' , true)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if (!$device) {
                DB::rollBack();

                return $this->respond([
                    'status' => 'error',
                    'message' => 'No device found for this user and plan device type'
                ], 404);
            }

            $startAt = now();
            $endAt = $startAt->copy()->addDays($plan->duration_days);
            $paymentStatus = $validated['status'] ?? 'success';
            $isActive = $paymentStatus === 'success';

            $subscription = Subscription::create([
                'user_id' => $validated['user_id'],
                'plan_id' => $plan->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'is_active' => $isActive,
                'renewed_by' => null,
            ]);

            $payment = PaymentHistory::create([
                'subscription_id' => $subscription->id,
                'user_id' => $validated['user_id'],
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
                    'device_id' => $device->id,
                    'device_token' => $device->device_token,
                    'device_name' => $device->device_name,
                ],
            ]);

            $device->update([
                'subscription_id' => $subscription->id,
                'last_activity' => now(),
                'status' => $device->status ?: 'inactive',
            ]);

            DB::commit();

            return $this->respond([
                'status' => 'success',
                'message' => 'Subscription and payment history created successfully.',
                'data' => [
                    'subscription' => $subscription->fresh('plan'),
                    'payment_history' => $payment,
                    'device' => $device->fresh(),
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
