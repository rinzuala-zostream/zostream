<?php

namespace App\Http\Controllers;

use App\Models\UserModel;
use App\Models\ZonetSubscriptionModel;
use App\Models\ZonetUserModel;
use Illuminate\Http\Request;
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
                'operator_id' => 'required|integer',
            ]);

            // Check if user exists in the users table
            $userExists = UserModel::where('uid', $request->id)
                ->orWhere('mail', $request->email)
                ->exists();

            if (!$userExists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found in main user table, please register first'
                ]);
            }

            // Check if already added to ZonetUserModel
            if ($request->id && ZonetUserModel::where('id', $request->id)->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User already exists in Zonet users'
                ]);
            }

            // Create new zonet user
            $zonetUser = new ZonetUserModel();
            $zonetUser->id = $request->id ?: UserModel::where('mail', $request->email)->value('uid');
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

    public function getAll()
    {
        try {
            $users = ZonetUserModel::with('user', 'subscriptions')->orderByDesc('created_at')->paginate(10);

            $users->getCollection()->transform(function ($zonetUser) {
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

            return response()->json([
                'status' => 'success',
                'message' => 'Fetched successfully',
                'data' => $users
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
            ]);

            $zonetUser = ZonetUserModel::where('num', $request->num)->first();

            if (!$zonetUser) {
                return response()->json(['status' => 'error', 'message' => 'Zonet user not found']);
            }

            $subscription = ZonetSubscriptionModel::create([
                'period' => $request->period,
                'user_num' => $zonetUser->num,
                'sub_plan' => $request->sub_plan ?? 'Thla 1',
                'create_date' => now()->format('F j, Y')
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription created successfully',
                'data' => $subscription
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
