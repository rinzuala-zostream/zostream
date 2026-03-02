<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
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

            $plans = Plan::where('is_active', true)
                ->orderBy('name')
                ->get()
                ->groupBy('name');

            $response = [];

            foreach ($plans as $planName => $planGroup) {

                $first = $planGroup->first();

                // Build per device price
                $perDevicePrice = [];

                foreach ($planGroup as $row) {
                    $perDevicePrice[ucfirst($row->device_type)] = (float) $row->price;
                }

                // Calculate original price (sum of device prices)
                $originalPrice = $planGroup->sum('price');

                // Auto generate features
                $features = [
                    "Watch on {$first->device_limit} device" . ($first->device_limit > 1 ? 's' : ''),
                ];

                // Example ad logic (customize if needed)
                if ($first->device_limit == 1) {
                    $features[] = "Ads";
                    $features[] = "PPV 2% discount";
                } elseif ($first->device_limit == 2) {
                    $features[] = "Ad 40%";
                    $features[] = "PPV 5% discount";
                } elseif ($first->device_limit == 3) {
                    $features[] = "Ad free";
                    $features[] = "PPV 7% discount";
                } elseif ($first->device_limit >= 4) {
                    $features[] = "Ad free";
                    $features[] = "PPV 10% discount";
                }

                $features[] = "Unlock all premium content";

                $response[] = [
                    "plan" => $planName,
                    "original_price" => (float) $originalPrice,
                    "per_device_price" => $perDevicePrice,
                    "duration_days" => (int) $first->duration_days,
                    "features" => $features
                ];
            }

            return response()->json([
                "status" => "success",
                "data" => $response
            ]);

        } catch (\Exception $e) {
            Log::error('Plan list error', ['error' => $e->getMessage()]);
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
    public function getByUser(Request $request, $userId)
    {
        try {
            $perPage = $request->get('per_page', 15);

            $subscriptions = Subscription::with(['plan', 'devices'])
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

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
            Log::error('Subscription getByUser error', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to retrieve user subscriptions', $e);
        }
    }

    /**
     * ➕ Create a new subscription
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'user_id' => 'required|string|max:225',
                'plan_id' => 'required|integer|exists:n_plans,id',
                'start_at' => 'nullable|date',
                'end_at' => 'nullable|date',
                'is_active' => 'nullable|boolean',
            ]);

            $plan = Plan::find($request->plan_id);

            if (!$plan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid plan ID'
                ], 404);
            }

            $startAt = $request->start_at ? Carbon::parse($request->start_at) : now();
            $endAt = $request->end_at ? Carbon::parse($request->end_at) : $startAt->copy()->addDays($plan->duration_days ?? 30);

            $subscription = Subscription::create([
                'user_id' => $request->user_id,
                'plan_id' => $plan->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'is_active' => $request->is_active ?? true,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription created successfully',
                'data' => $subscription
            ], 201);
        } catch (Exception $e) {
            Log::error('Subscription store error', ['error' => $e->getMessage()]);
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