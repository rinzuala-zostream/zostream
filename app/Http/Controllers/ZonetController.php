<?php

namespace App\Http\Controllers;

use App\Models\ZonetSubscriptionModel;
use App\Models\ZonetUserModel;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ZonetController extends Controller
{
    // ---------------- Zonet User ----------------

    public function insert(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required|string'
            ]);

            if (ZonetUserModel::where('id', $request->id)->exists()) {
                return response()->json(['message' => 'User already exists'], 409);
            }

            $zonetUser = new ZonetUserModel();
            $zonetUser->id = $request->id;
            $zonetUser->save();

            return response()->json(['message' => 'Inserted successfully', 'data' => $zonetUser]);
        } catch (ValidationException $ve) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $ve->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to insert user',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function getAll()
    {
        try {
            $users = ZonetUserModel::with('user', 'subscriptions')->orderByDesc('created_at')->paginate(10);
            return response()->json($users);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch users', 'details' => $e->getMessage()], 500);
        }
    }

    public function delete($id)
    {
        try {
            $deleted = ZonetUserModel::where('id', $id)->delete();

            if ($deleted) {
                return response()->json(['message' => 'Deleted successfully']);
            } else {
                return response()->json(['message' => 'User not found'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete user', 'details' => $e->getMessage()], 500);
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
                return response()->json(['message' => 'Zonet user not found'], 404);
            }

            $subscription = ZonetSubscriptionModel::create([
                'period' => $request->period,
                'user_num' => $zonetUser->num,
                'sub_plan' => $request->sub_plan ?? 'Thla 1', // Default to 'default' if not provided
                'create_date' => now()->format('F j, Y') // Store as formatted string
            ]);

            return response()->json([
                'message' => 'Subscription created successfully',
                'data' => $subscription
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to insert subscription',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function getAllSubscriptions()
    {
        try {
            $subscriptions = ZonetSubscriptionModel::orderByDesc('created_at')->paginate(10);
            return response()->json($subscriptions);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch subscriptions', 'details' => $e->getMessage()], 500);
        }
    }

    public function deleteSubscription($id)
    {
        try {
            $deleted = ZonetSubscriptionModel::where('id', $id)->delete();

            if ($deleted) {
                return response()->json(['message' => 'Subscription deleted successfully']);
            } else {
                return response()->json(['message' => 'Subscription not found'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete subscription', 'details' => $e->getMessage()], 500);
        }
    }
}
