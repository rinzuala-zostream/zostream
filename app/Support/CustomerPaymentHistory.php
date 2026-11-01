<?php

namespace App\Support;

use App\Models\New\PaymentHistory;
use Illuminate\Database\Eloquent\Builder;

final class CustomerPaymentHistory
{
    public const STATUSES = [
        'paid' => ['success', 'paid', 'completed', 'captured'],
        'pending' => ['pending', 'created', 'processing'],
        'failed' => ['failed', 'failure', 'cancelled', 'canceled'],
        'refunded' => ['refunded', 'refund'],
    ];

    public function query(string $userId): Builder
    {
        return PaymentHistory::with('plan')->where('user_id', $userId)->where(function ($query) {
            $query->where('app_payment_type', 'subscription')
                ->orWhere(function ($legacy) {
                    $legacy->whereNull('app_payment_type')->where(function ($subscription) {
                        $subscription->whereNotNull('subscription_id')->orWhereNotNull('plan_id');
                    });
                });
        });
    }

    public function filtered(string $userId, array $filters): Builder
    {
        [$from, $to] = CustomerStatsPeriod::range($filters);
        $query = $this->query($userId)->whereRaw('COALESCE(payment_date, created_at) BETWEEN ? AND ?', [$from, $to]);
        $status = $filters['status'] ?? 'all';
        if ($status !== 'all') {
            $values = self::STATUSES[$status];
            $query->whereRaw('LOWER(status) IN ('.implode(',', array_fill(0, count($values), '?')).')', $values);
        }
        $device = $filters['device'] ?? 'all';
        if ($device !== 'all') {
            $query->where(function ($query) use ($device) {
                $query->where('device_type', $device)->orWhere(function ($fallback) use ($device) {
                    $fallback->whereNull('device_type')->whereHas('plan', fn ($plan) => $plan->where('device_type', $device));
                });
            });
        }

        return $query->orderByRaw('COALESCE(payment_date, created_at) DESC')->orderByDesc('id');
    }

    public function format(PaymentHistory $payment): array
    {
        $meta = is_array($payment->meta) ? $payment->meta : [];
        $wifi = ($meta['provider'] ?? null) === 'zostream_wifi' && ($meta['subscription_origin'] ?? null) === 'zostream_wifi_connection';
        $rawStatus = strtolower((string) $payment->status);
        $status = 'unknown';
        foreach (self::STATUSES as $label => $values) {
            if (in_array($rawStatus, $values, true)) {
                $status = $label;
                break;
            }
        }

        return [
            'id' => $payment->id,
            'receipt_number' => ($wifi ? 'WIFI-INV-' : 'INV-').str_pad((string) $payment->id, 10, '0', STR_PAD_LEFT),
            'plan' => $payment->meta['plan_name'] ?? $payment->plan?->name ?? 'Subscription',
            'device_type' => $payment->device_type ?: $payment->plan?->device_type,
            'amount' => $wifi && is_numeric($meta['actual_amount'] ?? null) ? $meta['actual_amount'] : $payment->amount,
            'currency' => $payment->currency,
            'status' => $status,
            'payment_type' => $payment->payment_type,
            'payment_date' => ($payment->payment_date ?? $payment->created_at)?->toIso8601String(),
            // Never use today's subscription expiry for an older transaction.
            'valid_until' => $payment->expiry_date?->toDateString(),
            'payment_method' => $payment->payment_method,
            'payment_gateway' => $payment->payment_gateway,
            'transaction_id' => $payment->transaction_id,
            'receipt_available' => $status === 'paid',
        ];
    }

    public static function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^(\s*[=+\-@]|[\t\r\n])/u', $text) ? "'".$text : $text;
    }
}
