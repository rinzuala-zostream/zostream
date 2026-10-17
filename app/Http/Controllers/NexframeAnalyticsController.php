<?php

namespace App\Http\Controllers;

use App\Models\MovieModel;
use App\Models\New\Subscription;
use App\Models\UserModel;
use App\Services\NexframeBusinessAnalytics;
use App\Services\NexframeReportCache;
use App\Services\NexframeSocialAnalytics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

class NexframeAnalyticsController extends Controller
{
    public function __construct(private readonly NexframeBusinessAnalytics $business) {}

    public function overview(Request $request, NexframeSocialAnalytics $socialAnalytics)
    {
        $partnershipStart = config('services.nexframe_dashboard.partnership_started_at');
        $validated = $request->validate([
            'from' => array_filter(['nullable', 'date_format:Y-m-d', $partnershipStart ? 'after_or_equal:'.$partnershipStart : null]),
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'refresh' => ['nullable', 'boolean'],
            'comparison' => ['nullable', 'in:partnership,selected'],
        ]);

        $to = isset($validated['to'])
            ? Carbon::createFromFormat('Y-m-d', $validated['to'])->endOfDay()
            : now()->endOfDay();
        $from = isset($validated['from'])
            ? Carbon::createFromFormat('Y-m-d', $validated['from'])->startOfDay()
            : ($partnershipStart ? Carbon::parse($partnershipStart)->startOfDay() : $to->copy()->subDays(13)->startOfDay());

        $to = $to->min(now()->endOfDay());
        if ($from->gt($to)) {
            return response()->json(['message' => 'Select dates on or before today.'], 422);
        }

        $days = (int) $from->diffInDays($to->copy()->startOfDay()) + 1;

        if ($days > 367) {
            return response()->json(['message' => 'The selected range may not exceed 367 days.'], 422);
        }

        $comparison = $validated['comparison'] ?? 'partnership';
        $identity = [
            config('database.default'), config('database.connections.'.config('database.default')),
            config('database.connections.analytics'), config('services.nexframe_dashboard.partnership_started_at'),
            config('app.timezone'), $from->toDateString(), $to->toDateString(), $comparison,
        ];
        $key = 'nexframe:business:v3:'.hash('sha256', json_encode($identity));
        // Social refresh intentionally does not bypass the business cache.
        $report = app(NexframeReportCache::class)->remember($key, fn () => $this->businessReport($from, $to, $days, $comparison));
        $social = $socialAnalytics->report($from, $to, (bool) ($validated['refresh'] ?? false));
        foreach ($report['daily'] as &$row) {
            $row['social_engagement'] = $social['daily_engagement'][$row['date']] ?? null;
        }
        unset($row);
        $report['social'] = $social;
        $report['impact']['social_subscription_correlation'] = $this->correlation($report['daily'], 'social_engagement', 'subscriptions');
        $report['funnel'] = [
            ['label' => 'Combined social reach', 'value' => $social['reach'], 'available' => $social['reach'] !== null],
            ['label' => 'Content interactions', 'value' => $social['engagement'], 'available' => $social['engagement'] !== null],
            ['label' => 'New registrations', 'value' => $report['metrics']['registrations']['value'], 'available' => $report['metrics']['registrations']['value'] !== null],
            ['label' => 'First-time paying users', 'value' => $report['metrics']['subscriptions']['value'], 'available' => $report['metrics']['subscriptions']['value'] !== null],
            ['label' => 'Active streaming users', 'value' => $report['metrics']['streaming_users']['value'], 'available' => $report['source_status']['streaming']],
        ];
        $report['content'] = $this->latestContent($social['posts'], $social, $from, $to);
        $report['generated_at'] = now()->toIso8601String();

        return response()->json($report);
    }

    private function businessReport(Carbon $from, Carbon $to, int $days, string $comparison): array
    {
        $this->business->resetMemo();
        $previousTo = $from->copy()->subSecond();
        $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();

        $registrations = $this->registrationCount($from, $to);
        $previousRegistrations = $this->registrationCount($previousFrom, $previousTo);
        $subscriptions = $this->subscriptionCount($from, $to);
        $previousSubscriptions = $this->subscriptionCount($previousFrom, $previousTo);
        $activeSubscriptions = Schema::hasTable((new Subscription)->getTable())
            ? Subscription::query()->currentlyActive()->whereBetween('start_at', [$from, $to])->count()
            : null;

        $streaming = $this->streamingSummary($from, $to);
        $previousStreaming = $this->streamingSummary($previousFrom, $previousTo);
        $daily = $this->dailySeries($from, $to, []);
        $partnership = $this->partnershipComparison($to, $comparison === 'selected' ? $from : null);
        $business = $this->business;
        $subscriptionDetails = $business->subscriptions($from, $to);
        $conversion = $business->registrationConversion($from, $to);

        return [
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $days,
                'includes_today' => $to->isToday(),
                'previous_from' => $this->reportingRangeAllowed($previousFrom) ? $previousFrom->toDateString() : null,
                'previous_to' => $this->reportingRangeAllowed($previousFrom) ? $previousTo->toDateString() : null,
            ],
            'generated_at' => now()->toIso8601String(),
            'metrics' => [
                'registrations' => $this->metric($registrations, $previousRegistrations),
                'subscriptions' => $this->metric($subscriptions, $previousSubscriptions),
                'active_subscriptions' => ['value' => $activeSubscriptions, 'change' => null],
                'repeat_payments' => ['value' => $subscriptionDetails['repeat_payments'], 'change' => null],
                'successful_payments' => ['value' => $subscriptionDetails['successful_payments'], 'change' => null],
                'paying_users' => ['value' => $subscriptionDetails['paying_users'], 'change' => null],
                'average_daily_viewers' => ['value' => $streaming['average_daily_viewers'], 'change' => null],
                'streaming_users' => $this->metric($streaming['viewers'], $previousStreaming['viewers']),
                'streaming_sessions' => $this->metric($streaming['sessions'], $previousStreaming['sessions']),
                'watch_hours' => $this->metric($streaming['watch_hours'], $previousStreaming['watch_hours']),
            ],
            'source_status' => ['streaming' => $streaming['available'], 'payments' => $subscriptionDetails['available']],
            'streaming_source' => ['id' => $streaming['source'], 'label' => $streaming['source_label']],
            'daily' => $daily,
            'impact' => [
                'partnership_started_at' => config('services.nexframe_dashboard.partnership_started_at'),
                'partnership' => $partnership,
                'registration_change' => $this->metric($registrations, $previousRegistrations)['change'],
                'subscription_change' => $this->metric($subscriptions, $previousSubscriptions)['change'],
                'streaming_change' => $this->metric($streaming['viewers'], $previousStreaming['viewers'])['change'],
                'registration_to_subscription_rate' => $conversion['rate'],
                'registration_cohort' => $conversion,
                'attribution_status' => 'directional_analysis',
                'attribution_note' => 'This dashboard compares social activity with Zo Stream growth. It shows directional relationships, not individual-user attribution.',
            ],
        ];
    }

    private function registrationCount(Carbon $from, Carbon $to): ?int
    {
        if (! $this->reportingRangeAllowed($from)) {
            return null;
        }
        if (! Schema::hasColumn((new UserModel)->getTable(), 'created_date')) {
            return null;
        }

        return UserModel::whereBetween('created_date', [$from, $to])->count();
    }

    private function subscriptionCount(Carbon $from, Carbon $to): ?int
    {
        if (! $this->reportingRangeAllowed($from)) {
            return null;
        }

        return $this->business->subscriptions($from, $to)['first_time_payers'];
    }

    private function reportingRangeAllowed(Carbon $from): bool
    {
        $start = config('services.nexframe_dashboard.partnership_started_at');

        return ! $start || $from->gte(Carbon::parse($start)->startOfDay());
    }

    private function streamingSummary(Carbon $from, Carbon $to): array
    {
        if (! $this->reportingRangeAllowed($from)) {
            return ['viewers' => null, 'sessions' => null, 'watch_hours' => null, 'average_daily_viewers' => null];
        }

        return $this->business->streaming($from, $to);
    }

    private function dailySeries(Carbon $from, Carbon $to, array $socialEngagement): array
    {
        $registrations = collect();
        $registrationsAvailable = Schema::hasColumn((new UserModel)->getTable(), 'created_date');
        if ($registrationsAvailable) {
            $registrations = UserModel::query()
                ->whereBetween('created_date', [$from, $to])
                ->selectRaw('DATE(created_date) as metric_date, COUNT(*) as total')
                ->groupBy('metric_date')
                ->pluck('total', 'metric_date');
        }
        $subscriptions = collect();
        $paymentsAvailable = $this->business->paymentSourceAvailable();
        if ($paymentsAvailable) {
            $subscriptions = $this->business->firstPaymentsInRange($from, $to)
                ->selectRaw('DATE(COALESCE(payments.payment_date, payments.created_at)) as metric_date, COUNT(*) as total')
                ->groupBy('metric_date')
                ->pluck('total', 'metric_date');
        }
        $streaming = $this->business->streaming($from, $to);
        $streamsAvailable = $streaming['available'];
        $streams = $streaming['daily_users'];

        $series = [];
        for ($date = $from->copy()->startOfDay(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $series[] = [
                'date' => $key,
                'registrations' => $registrationsAvailable ? (int) ($registrations[$key] ?? 0) : null,
                'subscriptions' => $paymentsAvailable ? (int) ($subscriptions[$key] ?? 0) : null,
                'streaming_users' => $streamsAvailable ? (int) ($streams[$key] ?? 0) : null,
                'social_engagement' => isset($socialEngagement[$key]) ? (int) $socialEngagement[$key] : null,
            ];
        }

        return $series;
    }

    private function latestContent(array $socialPosts, array $social, Carbon $from, Carbon $to): array
    {
        $identity = [config('database.connections.'.config('database.default')), $from->toDateString(), $to->toDateString()];
        $key = 'nexframe:catalog:v2:'.hash('sha256', json_encode($identity));
        $catalog = app(NexframeReportCache::class)->remember($key, function () use ($from, $to) {
            $table = (new MovieModel)->getTable();
            if (! Schema::hasTable($table)) {
                return ['titles' => [], 'source_status' => ['catalog' => false]];
            }
            $columns = ['num', 'title', 'genre', 'create_date', 'views', 'poster'];
            if (Schema::hasColumn($table, 'opt_poster_url')) {
                $columns[] = 'opt_poster_url';
            }

            return ['titles' => MovieModel::query()->whereBetween('create_date', [$from, $to])->orderByDesc('create_date')->limit(6)->get($columns)
                ->map(fn (MovieModel $movie) => [
                    'id' => $movie->num, 'title' => $movie->title, 'category' => $movie->genre ?: '—',
                    'published_at' => $movie->create_date?->toDateString(),
                    'views' => (int) ($movie->views ?? 0),
                    'image' => $movie->getAttribute('opt_poster_url') ?: $movie->poster,
                ])->all(), 'source_status' => ['catalog' => true]];
        });

        return collect($catalog['titles'])->map(function (array $movie) use ($socialPosts, $social) {
            $title = str($movie['title'])->lower()->squish()->toString();
            $promotions = collect($socialPosts)->filter(fn (array $post) => mb_strlen($title) >= 3
                && str((string) ($post['caption'] ?? ''))->lower()->contains($title));

            return [...$movie,
                'social_posts' => $promotions->count(),
                'social_engagement' => (int) $promotions->sum('engagement'),
                'platform_promotions' => collect(['instagram', 'facebook'])->mapWithKeys(function ($platform) use ($promotions, $social) {
                    $matches = $promotions->where('platform', $platform);

                    return [$platform => [
                        'available' => (bool) ($social[$platform]['healthy'] ?? false),
                        'posts' => $matches->count(), 'engagement' => (int) $matches->sum('engagement'),
                    ]];
                })->all(),
                'social_status' => $promotions->isNotEmpty()
                    ? $promotions->count().' matching promotions'
                    : ($socialPosts ? 'No matching promotion' : 'Awaiting social sync'),
            ];
        })->values()->all();
    }

    private function metric(int|float|null $value, int|float|null $previous): array
    {
        if ($value === null || $previous === null) {
            return ['value' => $value, 'previous' => $previous, 'change' => null, 'available' => $value !== null];
        }
        $change = $previous > 0
            ? round((($value - $previous) / $previous) * 100, 1)
            : ($value > 0 ? null : 0.0);

        return ['value' => $value, 'previous' => $previous, 'change' => $change];
    }

    private function partnershipComparison(Carbon $selectedTo, ?Carbon $selectedFrom = null): array
    {
        $configured = config('services.nexframe_dashboard.partnership_started_at');

        try {
            $startedAt = filled($configured) ? Carbon::parse($configured)->startOfDay() : null;
        } catch (Throwable) {
            $startedAt = null;
        }

        $effectiveTo = $selectedTo->copy()->min(now()->endOfDay());
        $comparisonFrom = $selectedFrom?->copy()->startOfDay() ?? $startedAt;
        if (! $comparisonFrom || $comparisonFrom->gt($effectiveTo)) {
            return [
                'available' => false,
                'started_at' => $startedAt?->toDateString(),
                'message' => $startedAt ? 'The partnership period has not started yet.' : 'Partnership start date is not configured.',
            ];
        }

        $days = (int) $comparisonFrom->diffInDays($effectiveTo->copy()->startOfDay()) + 1;
        $baselineTo = $comparisonFrom->copy()->subSecond();
        $baselineFrom = $baselineTo->copy()->subDays($days - 1)->startOfDay();
        $sinceStreaming = $this->streamingSummary($comparisonFrom, $effectiveTo);
        $baselineStreaming = $this->streamingSummary($baselineFrom, $baselineTo);
        $since = [
            'registrations' => $this->registrationCount($comparisonFrom, $effectiveTo),
            'subscriptions' => $this->subscriptionCount($comparisonFrom, $effectiveTo),
            'streaming_users' => $sinceStreaming['viewers'],
            'watch_hours' => $sinceStreaming['watch_hours'],
        ];
        $baseline = [
            'registrations' => $this->registrationCount($baselineFrom, $baselineTo),
            'subscriptions' => $this->subscriptionCount($baselineFrom, $baselineTo),
            'streaming_users' => $baselineStreaming['viewers'],
            'watch_hours' => $baselineStreaming['watch_hours'],
        ];

        return [
            'available' => true,
            'started_at' => $startedAt?->toDateString(),
            'mode' => $selectedFrom ? 'selected' : 'partnership',
            'includes_today' => $effectiveTo->isToday(),
            'days' => $days,
            'confidence' => $days < 7 ? 'Early signal' : ($days < 30 ? 'Developing trend' : 'Established trend'),
            'baseline_available' => $this->reportingRangeAllowed($baselineFrom),
            'baseline_message' => 'Comparisons use only complete periods on or after the partnership start date.',
            'baseline_range' => $this->reportingRangeAllowed($baselineFrom) ? [
                'from' => $baselineFrom->toDateString(),
                'to' => $baselineTo->toDateString(),
            ] : null,
            'since_range' => [
                'from' => $comparisonFrom->toDateString(),
                'to' => $effectiveTo->toDateString(),
            ],
            'since' => $since,
            'baseline' => $baseline,
            'changes' => collect($since)->mapWithKeys(fn ($value, $key) => [
                $key => $this->metric($value, $baseline[$key])['change'],
            ])->all(),
            'daily_averages' => [
                'since' => array_replace(collect($since)->map(fn ($value) => $value === null ? null : round($value / $days, 1))->all(), ['streaming_users' => $sinceStreaming['average_daily_viewers']]),
                'baseline' => array_replace(collect($baseline)->map(fn ($value) => $value === null ? null : round($value / $days, 1))->all(), ['streaming_users' => $baselineStreaming['average_daily_viewers']]),
            ],
        ];
    }

    private function correlation(array $rows, string $firstKey, string $secondKey): ?float
    {
        $pairs = collect($rows)
            ->filter(fn (array $row) => $row['date'] < now()->toDateString() && $row[$firstKey] !== null && $row[$secondKey] !== null)
            ->map(fn (array $row) => [(float) $row[$firstKey], (float) $row[$secondKey]])
            ->values();

        if ($pairs->count() < 7) {
            return null;
        }

        $firstAverage = $pairs->avg(fn (array $pair) => $pair[0]);
        $secondAverage = $pairs->avg(fn (array $pair) => $pair[1]);
        $numerator = $pairs->sum(fn (array $pair) => ($pair[0] - $firstAverage) * ($pair[1] - $secondAverage));
        $firstSpread = $pairs->sum(fn (array $pair) => ($pair[0] - $firstAverage) ** 2);
        $secondSpread = $pairs->sum(fn (array $pair) => ($pair[1] - $secondAverage) ** 2);
        $denominator = sqrt($firstSpread * $secondSpread);

        return $denominator > 0 ? round($numerator / $denominator, 3) : null;
    }
}
