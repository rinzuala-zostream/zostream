<?php

namespace App\Http\Controllers;

use App\Models\UserModel;
use App\Models\ZonetOperator;
use App\Models\ZonetSubscriptionModel;
use App\Models\ZonetUserModel;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class ZonetController extends Controller
{
    // ---------------- Zonet User ----------------

    public function insert(Request $request)
    {
        try {
            $request->validate([
                'id' => 'nullable|string|required_without:email',
                'email' => 'nullable|email|required_without:id',
                'operator_id' => 'required|string',
                'username' => 'required|string',
                'name' => 'required|string',
            ]);

            // Step 1: Get user from `user` table based on `id` or `email`
            $user = UserModel::when($request->id, fn($q) => $q->where('uid', $request->id))
                ->when($request->email, fn($q) => $q->orWhere('mail', $request->email))
                ->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found in main user table, please register first'
                ]);
            }

            // Step 2: Prevent duplicate entry
            if (ZonetUserModel::where('id', $user->uid)->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User already exists in Zonet users'
                ]);
            }

            // Step 3: Create new Zonet user
            $zonetUser = new ZonetUserModel();
            $zonetUser->id = $user->uid;
            $zonetUser->operator_id = $request->operator_id;
            $zonetUser->username = $request->username;
            $zonetUser->name = $request->name;
            $zonetUser->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Inserted successfully',
                'data' => $zonetUser
            ]);
        } catch (ValidationException $ve) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'details' => $ve->errors()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to insert user',
                'details' => $e->getMessage()
            ]);
        }
    }

    public function getAll(Request $request)
    {
        try {
            $operatorId = $request->query('operator_id');
            $subscribe = $request->query('subscribe'); // true / false / null

            $users = ZonetUserModel::when($operatorId, function ($query) use ($operatorId) {
                return $query->where('operator_id', $operatorId);
            })
                ->with(['user', 'subscriptions'])
                ->orderByDesc('created_at')
                ->get();

            $users = $users->map(function ($zonetUser) {
                if ($zonetUser->subscriptions) {
                    try {
                        $startDate = Carbon::createFromFormat('F j, Y', $zonetUser->subscriptions->create_date);
                        $endDate = $startDate->copy()->addDays($zonetUser->subscriptions->period);

                        $zonetUser->subscriptions->end_date = $endDate->format('F j, Y');
                        $zonetUser->subscriptions->is_subscription_active = $endDate->isFuture();
                    } catch (\Exception $e) {
                        $zonetUser->subscriptions->end_date = null;
                        $zonetUser->subscriptions->is_subscription_active = false;
                    }
                }

                return $zonetUser;
            });

            // Count subscriptions before filtering
            $subscribeCount = $users->filter(fn($u) => $u->subscriptions?->is_subscription_active === true)->count();
            $unsubscribeCount = $users->filter(fn($u) => !$u->subscriptions || $u->subscriptions->is_subscription_active === false)->count();

            // Apply subscribe=true/false filter
            if ($subscribe === 'true') {
                $users = $users->filter(fn($u) => $u->subscriptions?->is_subscription_active === true);
            } elseif ($subscribe === 'false') {
                $users = $users->filter(fn($u) => !$u->subscriptions || $u->subscriptions->is_subscription_active === false);
            }

            // Paginate manually
            $page = $request->query('page', 1);
            $perPage = 10;
            $paginated = new LengthAwarePaginator(
                $users->forPage($page, $perPage)->values(),
                $users->count(),
                $perPage,
                $page
            );

            // Get operator wallet balance if operator_id is provided
            $walletBalance = null;
            if ($operatorId) {
                $operator = ZonetOperator::where('id', $operatorId)->first();
                $walletBalance = $operator?->wallet;
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Fetched successfully',
                'data' => $paginated,
                'subscribe_count' => $subscribeCount,
                'unsubscribe_count' => $unsubscribeCount,
                'wallet_balance' => $walletBalance, // ✅ Added here
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch users',
                'details' => $e->getMessage()
            ]);
        }
    }

    public function delete($id)
    {
        try {
            $deleted = ZonetUserModel::where('id', $id)->delete();

            if ($deleted) {
                return response()->json(['status' => 'success', 'message' => 'Deleted successfully']);
            } else {
                return response()->json(['status' => 'error', 'message' => 'User not found']);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Failed to delete user', 'details' => $e->getMessage()]);
        }
    }

    // ---------------- Subscriptions ----------------

    public function insertSubscription(Request $request)
    {
        try {
            $request->validate([
                'period' => 'required|int|max:255',
                'num' => 'required|int|exists:zonet_users,num',
                'create_date' => 'nullable|date',
            ]);

            // Step 1: Find Zonet user
            $zonetUser = ZonetUserModel::where('num', $request->num)->first();

            if (!$zonetUser) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Zonet user not found'
                ]);
            }

            // Step 2: Find the operator
            $operator = ZonetOperator::where('id', $zonetUser->operator_id)->first();
            if (!$operator) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Operator not found'
                ]);
            }

            // Step 3: Check wallet balance
            $subscriptionCost = 120;
            if ($operator->wallet < $subscriptionCost) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient wallet balance. 122 required.'
                ]);
            }

            // Step 4: Deduct wallet
            $operator->wallet -= $subscriptionCost;
            $operator->save();

            // Step 5: Create subscription
            $subscription = ZonetSubscriptionModel::create([
                'period' => $request->period,
                'user_num' => $zonetUser->num,
                'sub_plan' => $request->sub_plan ?? 'Thla 1',
                'create_date' => $request->create_date
                    ? Carbon::parse($request->create_date)->format('F j, Y')
                    : now()->format('F j, Y')
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription created and 122 deducted from wallet',
                'data' => $subscription,
                'wallet_balance' => $operator->wallet
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to insert subscription',
                'details' => $e->getMessage()
            ]);
        }
    }

    public function getAllSubscriptions()
    {
        try {
            $subscriptions = ZonetSubscriptionModel::orderByDesc('created_at')->paginate(10);

            // Add end_date and is_subscription_active
            $subscriptions->getCollection()->transform(function ($subscription) {
                try {
                    $startDate = Carbon::createFromFormat('F j, Y', $subscription->create_date);
                    $endDate = $startDate->copy()->addDays($subscription->period);

                    $subscription->end_date = $endDate->format('F j, Y');
                    $subscription->is_subscription_active = $endDate->isFuture();
                } catch (\Exception $e) {
                    $subscription->end_date = null;
                    $subscription->is_subscription_active = false;
                }

                return $subscription;
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Fetched successfully',
                'data' => $subscriptions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch subscriptions',
                'details' => $e->getMessage()
            ]);
        }
    }

    public function deleteSubscription($id)
    {
        try {
            $deleted = ZonetSubscriptionModel::where('id', $id)->delete();

            if ($deleted) {
                return response()->json(['status' => 'success', 'message' => 'Subscription deleted successfully']);
            } else {
                return response()->json(['status' => 'error', 'message' => 'Subscription not found']);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Failed to delete subscription', 'details' => $e->getMessage()]);
        }
    }
}
