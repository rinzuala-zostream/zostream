<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
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
                    "plan" => $planName,
                    "original_price" => (float) $planGroup->sum('price'),
                    "duration_days" => (int) $first->duration_days,
                    "per_device_price" => $perDevicePrice,
                    "per_device_features" => $deviceFeatures
                ];
            }

            return response()->json([
                "status" => "success",
                "data" => $response
            ]);

        } catch (\Exception $e) {
            return response()->json([
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
                return response()->json([
                    'status' => 'error',
                    'message' => 'Subscription not found'
                ], 404);
            }

            return response()->json([
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
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Invalid device type. Allowed: mobile, browser, tv'
                    ], 422);
                }

                $subscription = $query->whereHas('plan', function ($q) use ($deviceType) {
                    $q->where('device_type', $deviceType);
                })->first(); // 👈 important

                if (!$subscription) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'No active subscription found for this device type'
                    ], 404);
                }

                return response()->json($subscription // 👈 object
                );
            }

            // ✅ If no device_type → return paginated list
            $subscriptions = $query->paginate($perPage);

            if ($subscriptions->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No subscriptions found for this user'
                ], 404);
            }

            return response()->json([
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
                'plan_id' => 'required|integer|exists:n_plans,id',
                'app_payment_type' => 'nullable|string|max:100',
                'payment_method' => 'nullable|string|max:100',
                'payment_gateway' => 'nullable|string|max:100',
                'transaction_id' => 'nullable|string|max:255',
                'currency' => 'nullable|string|max:10',
            ]);

            $plan = Plan::find($request->plan_id);

            if (!$plan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid plan ID'
                ], 404);
            }

            $startAt = now();
            $endAt = $startAt->copy()->addDays($plan->duration_days);

            // ✅ Create Subscription
            $subscription = Subscription::create([
                'user_id' => $request->user_id,
                'plan_id' => $plan->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'is_active' => false, // start as false until payment success
            ]);

            // ✅ Create Payment History (PENDING)
            PaymentHistory::create([
                'subscription_id' => $subscription->id,
                'user_id' => $request->user_id,
                'app_payment_type' => $request->app_payment_type,
                'amount' => $plan->price,
                'currency' => $request->currency ?? 'INR',
                'payment_method' => $request->payment_method,
                'payment_gateway' => $request->payment_gateway,
                'transaction_id' => $request->transaction_id,
                'status' => 'pending', // 🔥 important
                'payment_type' => 'new',
                'payment_date' => now(),
                'expiry_date' => $endAt,
                'meta' => null,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription created. Payment pending.',
                'data' => $subscription
            ], 201);

        } catch (Exception $e) {

            DB::rollBack();

            Log::error('Subscription store error', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to create subscription', $e);
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
                return response()->json([
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

            return response()->json([
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
                return response()->json([
                    'status' => 'error',
                    'message' => 'Subscription not found'
                ], 404);
            }

            $subscription->delete();

            return response()->json([
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
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'error' => $e->getMessage(),
        ], $code);
    }
}