<?php

namespace App\Http\Controllers;

use App\Models\BrowserSubscriptionModel;
use App\Models\MovieModel;
use App\Models\SubscriptionModel;
use App\Models\TVSubscriptionModel;
use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminDashboardController extends Controller
{
    // 1. Total user count
    public function getUserStats()
    {
        try {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_users' => UserModel::count(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 2. Movie stats: category-wise + total
    public function getMovieStats(Request $request)
    {
        try {
            $month = $request->query('month'); // format: YYYY-MM
            $start = $month ? Carbon::parse($month)->startOfMonth() : null;
            $end = $month ? Carbon::parse($month)->endOfMonth() : null;

            $categoryFlags = [
                'Hollywood' => 'isHollywood',
                'Bollywood' => 'isBollywood',
                'Mizo' => 'isMizo',
                'Korean' => 'isKorean',
                'Documentary' => 'isDocumentary',
                'Series' => 'isSeason',
                'PayPerView' => 'isPayPerView',
                'Premium' => 'isPremium',
                'Age Restricted' => 'isAgeRestricted',
            ];

            $movieCounts = collect($categoryFlags)->mapWithKeys(function ($column, $label) use ($start, $end) {
                $query = MovieModel::where($column, true);
                if ($start && $end) {
                    $query->whereBetween('create_date', [$start, $end]);
                }
                return [$label => $query->count()];
            });

            $totalQuery = MovieModel::query();
            if ($start && $end) {
                $totalQuery->whereBetween('create_date', [$start, $end]);
            }
            $totalMovies = $totalQuery->count();

            return response()->json([
                'status' => 'success',
                'month' => $month ?? 'All',
                'data' => [
                    'total_movies' => $totalMovies,
                    'movies_by_category' => $movieCounts
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 3. Subscription stats
    public function getSubscriptionStats(Request $request)
    {
        try {
            // If not provided, default to current month
            $month = $request->query('month') ?? now()->format('Y-m');
            $device = $request->query('device_type'); // Mobile, TV, Browser, or null for All

            $start = Carbon::parse($month)->startOfMonth();
            $end = Carbon::parse($month)->endOfMonth();

            $plans = ['Kar 1', 'Thla 1', 'Thla 6', 'Kum 1'];
            $subscriptionCounts = [];

            foreach ($plans as $plan) {
                $count = 0;

                if (!$device || $device === 'Mobile') {
                    $records = SubscriptionModel::where('sub_plan', $plan)->get();
                    $filtered = $records->filter(function ($item) use ($start, $end) {
                        try {
                            $date = Carbon::parse($item->created_at);
                            return $date->between($start, $end);
                        } catch (\Exception $e) {
                            return false;
                        }
                    });
                    $count += $filtered->count();
                }

                if (!$device || $device === 'TV') {
                    $records = TVSubscriptionModel::where('sub_plan', $plan)->get();
                    $filtered = $records->filter(function ($item) use ($start, $end) {
                        try {
                            $date = Carbon::createFromFormat('F d, Y', $item->create_date);
                            return $date->between($start, $end);
                        } catch (\Exception $e) {
                            return false;
                        }
                    });
                    $count += $filtered->count();
                }

                if (!$device || $device === 'Browser') {
                    $records = BrowserSubscriptionModel::where('sub_plan', $plan)->get();
                    $filtered = $records->filter(function ($item) use ($start, $end) {
                        try {
                            $date = Carbon::parse($item->created_at);
                            return $date->between($start, $end);
                        } catch (\Exception $e) {
                            return false;
                        }
                    });
                    $count += $filtered->count();
                }

                $subscriptionCounts[$plan] = $count;
            }

            // Free users (all-time)
            $subscribedIds = collect()
                ->merge(SubscriptionModel::pluck('id'))
                ->merge(TVSubscriptionModel::pluck('id'))
                ->merge(BrowserSubscriptionModel::pluck('id'))
                ->unique();

            $freeUsers = UserModel::whereNotIn('uid', $subscribedIds)->count();
            $subscriptionCounts['Free Users'] = $freeUsers;

            return response()->json([
                'status' => 'success',
                'month' => $month,
                'device_filter' => $device ?? 'All',
                'data' => [
                    'subscriptions' => $subscriptionCounts,
                    'total_subscribed_users' => collect($subscriptionCounts)->except('Free Users')->sum()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
