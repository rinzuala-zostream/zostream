<?php

namespace App\Services;

use App\Models\New\PaymentHistory;
use App\Models\UserModel;
use App\Models\WatchSession;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class NexframeBusinessAnalytics
{
    private ?bool $paymentSource = null;

    private array $subscriptionReports = [];

    private array $streamingReports = [];

    private ?array $streamingSource = null;

    public function resetMemo(): void
    {
        $this->paymentSource = null;
        $this->subscriptionReports = [];
        $this->streamingReports = [];
        $this->streamingSource = null;
    }

    public function paymentSourceAvailable(): bool
    {
        return $this->paymentSource ??= Schema::hasColumns((new PaymentHistory)->getTable(), [
            'id', 'user_id', 'status', 'amount', 'app_payment_type', 'payment_date', 'created_at',
        ]);
    }

    public function successfulPayments(): Builder
    {
        return DB::table((new PaymentHistory)->getTable().' as payments')
            ->where('payments.status', 'success')
            ->where('payments.amount', '>', 0)
            ->where('payments.app_payment_type', 'subscription')
            ->whereNotNull('payments.user_id');
    }

    public function paymentsInRange(Carbon $from, Carbon $to): Builder
    {
        return $this->successfulPayments()
            ->whereBetween(DB::raw('COALESCE(payments.payment_date, payments.created_at)'), [$from, $to]);
    }

    public function firstPaymentsInRange(Carbon $from, Carbon $to): Builder
    {
        return $this->paymentsInRange($from, $to)->whereNotExists(function (Builder $query) {
            $query->selectRaw('1')->from((new PaymentHistory)->getTable().' as earlier')
                ->whereColumn('earlier.user_id', 'payments.user_id')
                ->where('earlier.status', 'success')->where('earlier.amount', '>', 0)
                ->where('earlier.app_payment_type', 'subscription')
                ->where(function (Builder $before) {
                    $before->whereRaw('COALESCE(earlier.payment_date, earlier.created_at) < COALESCE(payments.payment_date, payments.created_at)')
                        ->orWhere(function (Builder $sameTime) {
                            $sameTime->whereRaw('COALESCE(earlier.payment_date, earlier.created_at) = COALESCE(payments.payment_date, payments.created_at)')
                                ->whereColumn('earlier.id', '<', 'payments.id');
                        });
                });
        });
    }

    public function subscriptions(Carbon $from, Carbon $to): array
    {
        $key = $from->format('Y-m-d H:i:s.uP').'|'.$to->format('Y-m-d H:i:s.uP');

        return $this->subscriptionReports[$key] ??= $this->subscriptionReport($from, $to);
    }

    private function subscriptionReport(Carbon $from, Carbon $to): array
    {
        if (! $this->paymentSourceAvailable()) {
            return ['available' => false, 'first_time_payers' => null, 'repeat_payments' => null, 'successful_payments' => null, 'paying_users' => null];
        }

        $first = $this->firstPaymentsInRange($from, $to)->count();
        $total = $this->paymentsInRange($from, $to)->count();

        return [
            'available' => true,
            'first_time_payers' => $first,
            'repeat_payments' => $total - $first,
            'successful_payments' => $total,
            'paying_users' => $this->paymentsInRange($from, $to)->distinct()->count('payments.user_id'),
        ];
    }

    public function registrationConversion(Carbon $from, Carbon $to): array
    {
        $table = (new UserModel)->getTable();
        if (! $this->paymentSourceAvailable() || ! Schema::hasColumns($table, ['uid', 'created_date'])) {
            return ['registrations' => null, 'converted_users' => null, 'rate' => null];
        }

        $cohort = DB::table($table)->whereBetween('created_date', [$from, $to])->whereNotNull('uid');
        $registrations = (clone $cohort)->distinct()->count('uid');
        $converted = $this->paymentsInRange($from, $to)
            ->whereIn('payments.user_id', (clone $cohort)->select('uid'))
            ->distinct()->count('payments.user_id');

        return [
            'registrations' => $registrations,
            'converted_users' => $converted,
            'rate' => $registrations > 0 ? round($converted / $registrations * 100, 1) : null,
        ];
    }

    public function streaming(Carbon $from, Carbon $to): array
    {
        $key = $from->format('Y-m-d H:i:s.uP').'|'.$to->format('Y-m-d H:i:s.uP');

        return $this->streamingReports[$key] ??= $this->streamingReport($from, $to);
    }

    private function resolveStreamingSource(): array
    {
        if ($this->streamingSource !== null) {
            return $this->streamingSource;
        }
        // Use the same source as V4 analytics first, then existing application
        // watch statistics. Never combine these streams or sum daily uniques.
        foreach (array_unique(['analytics', config('database.default')]) as $connection) {
            try {
                if (Schema::connection($connection)->hasColumns('playback_sessions', ['started_at', 'user_id', 'watched_ms'])) {
                    return $this->streamingSource = [
                        'id' => 'playback_sessions', 'label' => 'Zo Stream playback analytics',
                        'connection' => $connection, 'table' => 'playback_sessions',
                        'date' => 'started_at', 'duration' => 'watched_ms', 'divisor' => 3_600_000,
                        'sessions_available' => true,
                    ];
                }
            } catch (Throwable) {
                // Legacy watch statistics may still be available.
            }
        }
        try {
            $watch = new WatchSession;
            $connection = $watch->getConnectionName() ?: config('database.default');
            if (Schema::connection($connection)->hasColumns($watch->getTable(), ['created_at', 'user_id', 'seconds_watched'])) {
                return $this->streamingSource = [
                    'id' => 'watch_sessions', 'label' => 'Zo Stream watch-duration logs',
                    'connection' => $connection, 'table' => $watch->getTable(),
                    'date' => 'created_at', 'duration' => 'seconds_watched', 'divisor' => 3600,
                    'sessions_available' => false,
                ];
            }
        } catch (Throwable) {
            // Keep unavailable data distinct from a reported zero.
        }

        return $this->streamingSource = ['id' => null, 'label' => 'Unavailable'];
    }

    private function streamingReport(Carbon $from, Carbon $to): array
    {
        $source = $this->resolveStreamingSource();
        $unavailable = ['available' => false, 'sessions' => null, 'viewers' => null, 'watch_hours' => null, 'average_daily_viewers' => null, 'daily_users' => [], 'source' => $source['id'], 'source_label' => $source['label']];
        if ($source['id'] === null) {
            return $unavailable;
        }
        try {
            $query = DB::connection($source['connection'])->table($source['table'])->whereBetween($source['date'], [$from, $to]);
            $totals = (clone $query)->selectRaw('COUNT(*) as records, COUNT(DISTINCT user_id) as viewers, COALESCE(SUM('.$source['duration'].'), 0) as duration')->first();
            $dailyViewers = (clone $query)->selectRaw('DATE('.$source['date'].') as day, COUNT(DISTINCT user_id) as viewers')->groupBy('day')->get();
            $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;

            return [
                'available' => true,
                'sessions' => $source['sessions_available'] ? (int) $totals->records : null,
                'viewers' => (int) $totals->viewers,
                'watch_hours' => round($totals->duration / $source['divisor'], 1),
                'average_daily_viewers' => round($dailyViewers->sum('viewers') / $days, 1),
                'daily_users' => $dailyViewers->mapWithKeys(fn ($row) => [$row->day => (int) $row->viewers])->all(),
                'source' => $source['id'],
                'source_label' => $source['label'],
            ];
        } catch (Throwable) {
            return $unavailable;
        }
    }
}
