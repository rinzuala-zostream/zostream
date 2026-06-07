<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Models\MovieModel;
use App\Models\New\Episode;
use App\Models\New\Plan;
use App\Models\New\Season;
use App\Models\New\Subscription;
use App\Models\UserModel;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        try {
            $validated = $request->validate([
                'period' => ['nullable', 'string', Rule::in(['daily', 'monthly', 'yearly', 'custom'])],
                'date' => 'nullable|date_format:Y-m-d',
                'month' => 'nullable|date_format:Y-m',
                'year' => 'nullable|integer|min:2000|max:2100',
                'start_date' => 'nullable|date_format:Y-m-d',
                'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
                'device_type' => ['nullable', 'string', Rule::in(['mobile', 'browser', 'tv'])],
                'date_field' => ['nullable', 'string', Rule::in(['created_at', 'start_at', 'end_at'])],
            ]);

            [$rangeStart, $rangeEnd, $period] = $this->resolveRange($validated);
            $deviceType = $validated['device_type'] ?? null;
            $dateField = $validated['date_field'] ?? 'created_at';

            $activeSubscriptionsQuery = Subscription::query()
                ->where('is_active', true)
                ->where('end_at', '>=', now());

            if ($deviceType) {
                $activeSubscriptionsQuery->whereHas('plan', function ($query) use ($deviceType) {
                    $query->where('device_type', $deviceType);
                });
            }

            $filteredActiveSubscriptionAggregates = DB::table('n_subscriptions as subscriptions')
                ->join('n_plans as plans', 'plans.id', '=', 'subscriptions.plan_id')
                ->where('subscriptions.is_active', true)
                ->where('subscriptions.end_at', '>=', now())
                ->when($deviceType, function ($query) use ($deviceType) {
                    $query->where('plans.device_type', $deviceType);
                })
                ->when($rangeStart && $rangeEnd, function ($query) use ($dateField, $rangeStart, $rangeEnd) {
                    $query->whereBetween("subscriptions.{$dateField}", [$rangeStart, $rangeEnd]);
                });

            $activeSubscriptionsByPlan = (clone $filteredActiveSubscriptionAggregates)
                ->select([
                    'subscriptions.plan_id',
                    'plans.name as plan_name',
                    'plans.device_type',
                    'plans.duration_days',
                    'plans.price as plan_price',
                ])
                ->selectRaw('COUNT(*) as total_active_subscriptions')
                ->selectRaw('SUM(plans.price) as total_amount')
                ->groupBy(
                    'subscriptions.plan_id',
                    'plans.name',
                    'plans.device_type',
                    'plans.duration_days',
                    'plans.price'
                )
                ->orderBy('plans.name')
                ->orderBy('plans.device_type')
                ->get()
                ->map(function ($item) {
                    return [
                        'plan_id' => (int) $item->plan_id,
                        'plan_name' => $item->plan_name,
                        'device_type' => $item->device_type,
                        'duration_days' => (int) ($item->duration_days ?? 0),
                        'plan_price' => (float) ($item->plan_price ?? 0),
                        'total_active_subscriptions' => (int) $item->total_active_subscriptions,
                        'total_amount' => round((float) ($item->total_amount ?? 0), 2),
                    ];
                })
                ->values();

            $activeSubscriptionsByDevice = (clone $filteredActiveSubscriptionAggregates)
                ->selectRaw("COALESCE(plans.device_type, 'unknown') as device_type")
                ->selectRaw('COUNT(*) as total_active_subscriptions')
                ->selectRaw('SUM(plans.price) as total_amount')
                ->groupBy('plans.device_type')
                ->orderBy('plans.device_type')
                ->get()
                ->map(function ($item) {
                    return [
                        'device_type' => $item->device_type,
                        'total_active_subscriptions' => (int) $item->total_active_subscriptions,
                        'total_amount' => round((float) ($item->total_amount ?? 0), 2),
                    ];
                })
                ->values();

            $planAmountSummary = (clone $filteredActiveSubscriptionAggregates)
                ->selectRaw('COUNT(*) as total_active_subscriptions_in_range')
                ->selectRaw('SUM(plans.price) as total_amount')
                ->first();

            $movieCategoryMap = [
                'hollywood' => 'isHollywood',
                'bollywood' => 'isBollywood',
                'mizo' => 'isMizo',
                'korean' => 'isKorean',
                'documentary' => 'isDocumentary',
                'series' => 'isSeason',
                'pay_per_view' => 'isPayPerView',
                'premium' => 'isPremium',
                'age_restricted' => 'isAgeRestricted',
                'dubbed' => 'isDubbed',
                'child_mode' => 'isChildMode',
            ];

            $moviesByCategory = collect($movieCategoryMap)->mapWithKeys(function ($column, $label) {
                return [$label => MovieModel::where($column, true)->count()];
            });

            $totalActiveUsers = UserModel::where('isACActive', true)->count();
            $totalUsersWithActiveSubscription = Subscription::query()
                ->where('is_active', true)
                ->where('end_at', '>=', now())
                ->when($deviceType, function ($query) use ($deviceType) {
                    $query->whereHas('plan', function ($planQuery) use ($deviceType) {
                        $planQuery->where('device_type', $deviceType);
                    });
                })
                ->distinct()
                ->count('user_id');

            $totalActiveSubscriptions = (clone $activeSubscriptionsQuery)->count();
            $totalMovies = MovieModel::count();
            $totalEpisodes = Episode::count();
            $totalSeasons = Season::count();

            return response()->json([
                'status' => 'success',
                'message' => 'Dashboard data fetched successfully',
                'filters' => [
                    'period' => $period,
                    'device_type' => $deviceType ?? 'all',
                    'date_field' => $dateField,
                    'start_date' => $rangeStart?->toDateTimeString(),
                    'end_date' => $rangeEnd?->toDateTimeString(),
                ],
                'data' => [
                    'overview' => [
                        'total_active_users' => $totalActiveUsers,
                        'total_users_with_active_subscription' => $totalUsersWithActiveSubscription,
                        'total_active_subscriptions' => $totalActiveSubscriptions,
                        'total_movies' => $totalMovies,
                        'total_episodes' => $totalEpisodes,
                        'total_seasons' => $totalSeasons,
                    ],
                    'active_subscriptions_by_plan' => $activeSubscriptionsByPlan,
                    'active_subscriptions_by_device' => $activeSubscriptionsByDevice,
                    'plan_amount_summary' => [
                        'total_active_subscriptions_in_range' => (int) ($planAmountSummary?->total_active_subscriptions_in_range ?? 0),
                        'total_amount' => round((float) ($planAmountSummary?->total_amount ?? 0), 2),
                    ],
                    'content' => [
                        'total_movies' => $totalMovies,
                        'movies_by_category' => $moviesByCategory,
                        'total_episodes' => $totalEpisodes,
                        'total_seasons' => $totalSeasons,
                        'total_active_plans' => Plan::where('is_active', true)->count(),
                    ],
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Dashboard index error', [
                'payload' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch dashboard data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function resolveRange(array $validated): array
    {
        $period = $validated['period'] ?? 'monthly';
        $rangeStart = null;
        $rangeEnd = null;

        if ($period === 'daily') {
            $date = isset($validated['date'])
                ? Carbon::createFromFormat('Y-m-d', $validated['date'])
                : now();

            $rangeStart = $date->copy()->startOfDay();
            $rangeEnd = $date->copy()->endOfDay();
        }

        if ($period === 'monthly') {
            $month = isset($validated['month'])
                ? Carbon::createFromFormat('Y-m', $validated['month'])
                : now();

            $rangeStart = $month->copy()->startOfMonth();
            $rangeEnd = $month->copy()->endOfMonth();
        }

        if ($period === 'yearly') {
            $year = isset($validated['year'])
                ? Carbon::create((int) $validated['year'], 1, 1)
                : now()->copy()->startOfYear();

            $rangeStart = $year->copy()->startOfYear();
            $rangeEnd = $year->copy()->endOfYear();
        }

        if ($period === 'custom') {
            if (empty($validated['start_date']) || empty($validated['end_date'])) {
                throw ValidationException::withMessages([
                    'start_date' => ['The start_date field is required when period is custom.'],
                    'end_date' => ['The end_date field is required when period is custom.'],
                ]);
            }

            $rangeStart = Carbon::createFromFormat('Y-m-d', $validated['start_date'])->startOfDay();
            $rangeEnd = Carbon::createFromFormat('Y-m-d', $validated['end_date'])->endOfDay();
        }

        return [$rangeStart, $rangeEnd, $period];
    }
}
