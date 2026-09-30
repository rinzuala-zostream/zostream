<?php

namespace App\Isp\Services;

use App\Http\Controllers\New\SubscriptionController;
use App\Isp\Models\Customer;
use App\Isp\Models\Package;
use App\Models\New\Plan;
use App\Models\New\Subscription;
use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ZoStreamSubscriptionService
{
    private const COMPLIMENTARY_PLAN_IDS = [22, 24];

    public function __construct(private readonly SubscriptionController $subscriptions) {}

    public function createOrder(Customer $customer, ?Package $package = null): array
    {
        $customer->loadMissing(['package', 'branch']);
        $package ??= $customer->package;
        if (! $package || (float) $package->price <= 0) {
            throw new RuntimeException('The customer does not have a payable package.');
        }
        $packageAmount = (float) $package->price;
        $ottDeduction = max(0, (float) ($customer->branch?->ott_deduction ?? 0));
        $operatorPercentage = min(100, max(0, (float) ($customer->branch?->operator_percentage
            ?? config('services.zostream_subscription.operator_percentage', 20))));
        $distributableAmount = $packageAmount - $ottDeduction;
        $operatorCommission = $distributableAmount * ($operatorPercentage / 100);
        $wifiShare = $distributableAmount - $operatorCommission;
        $payableAmount = $wifiShare + $ottDeduction;
        if ($payableAmount <= 0) {
            throw new RuntimeException('The package amount must be greater than the OTT deduction.');
        }
        if (blank($customer->phone)) {
            throw new RuntimeException('The customer phone number is required for ZoStream payment.');
        }

        $request = Request::create('/api/v4/external/subscription-history', 'POST', [
            'phone_number' => $customer->phone,
            'amount' => $payableAmount,
            'actual_amount' => $packageAmount,
            'name' => $customer->name,
            'currency' => 'INR',
            'meta' => [
                'source_name' => (string) config('services.zostream_subscription.source_name', 'zostream-isp-panel'),
            ],
            'gateway' => 'cashfree',
        ]);
        $request->headers->set(
            'X-CF-Env',
            (string) config('cashfree.env', 'SANDBOX')
        );

        $response = $this->subscriptions->storeExternalHistory($request);
        $data = $response->getData(true);
        if (! $response->isSuccessful()) {
            throw new RuntimeException((string) (data_get($data, 'message') ?: 'ZoStream subscription order creation failed.'));
        }
        $order = data_get($data, 'cashfree_order');
        if (data_get($data, 'status') !== 'success' || ! is_array($order) || blank($order['order_id'] ?? null)) {
            throw new RuntimeException((string) (data_get($data, 'message') ?: 'ZoStream API did not return a Cashfree order.'));
        }
        $expectedAmount = (int) round($payableAmount * 100);
        $actualAmount = (int) round(((float) ($order['order_amount'] ?? 0)) * 100);
        if ($actualAmount !== $expectedAmount || strtoupper((string) ($order['order_currency'] ?? '')) !== 'INR') {
            throw new RuntimeException('ZoStream API returned an order with an unexpected amount or currency.');
        }
        if (blank(data_get($data, 'payment_session_id'))) {
            throw new RuntimeException('ZoStream API did not return the Cashfree payment session ID.');
        }

        return $data;
    }

    public function activateComplimentaryAccess(Customer $customer): array
    {
        $phone = substr(preg_replace('/\D+/', '', (string) $customer->phone) ?: '', -10);
        if (strlen($phone) !== 10) {
            throw new RuntimeException('A valid 10-digit customer phone number is required for ZoStream access.');
        }

        $plans = Plan::query()
            ->whereIn('id', self::COMPLIMENTARY_PLAN_IDS)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');
        $missing = collect(self::COMPLIMENTARY_PLAN_IDS)->reject(fn (int $id): bool => $plans->has($id));
        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Required ZoStream Mobile/TV plans are unavailable: '.$missing->implode(', ').'.');
        }

        return DB::transaction(function () use ($customer, $phone, $plans): array {
            $user = UserModel::query()->where('auth_phone', $phone)->lockForUpdate()->first();
            $userCreated = false;
            if (! $user) {
                $user = UserModel::create([
                    'uid' => (string) Str::uuid(),
                    'auth_phone' => $phone,
                    'name' => $customer->name,
                    'created_date' => now()->format('M d, Y h:i:s a'),
                    'device_name' => 'ZoStream ISP',
                    'isACActive' => true,
                    'isAccountComplete' => false,
                    'is_auth_phone_active' => true,
                ]);
                $userCreated = true;
            }

            $subscriptions = [];
            foreach (self::COMPLIMENTARY_PLAN_IDS as $planId) {
                $plan = $plans->get($planId);
                $targetEnd = $customer->expires_at
                    ? $customer->expires_at->copy()->endOfDay()
                    : Subscription::endAtForDuration(now(), (int) $plan->duration_days);
                if ($targetEnd->isPast()) {
                    $targetEnd = Subscription::endAtForDuration(now(), (int) $plan->duration_days);
                }

                $subscription = Subscription::query()
                    ->where('user_id', $user->uid)
                    ->whereHas('plan', fn ($query) => $query->where('device_type', $plan->device_type))
                    ->latest('end_at')
                    ->lockForUpdate()
                    ->first();

                if ($subscription) {
                    $endAt = $subscription->end_at && $subscription->end_at->gt($targetEnd)
                        ? $subscription->end_at
                        : $targetEnd;
                    $subscription->update([
                        'plan_id' => $plan->id,
                        'start_at' => $subscription->start_at ?? now(),
                        'end_at' => $endAt,
                        'is_active' => true,
                    ]);
                } else {
                    $subscription = Subscription::create([
                        'user_id' => $user->uid,
                        'plan_id' => $plan->id,
                        'start_at' => now(),
                        'end_at' => $targetEnd,
                        'is_active' => true,
                    ]);
                }

                $subscriptions[] = $subscription->fresh('plan');
            }

            return [
                'user_id' => $user->uid,
                'user_created' => $userCreated,
                'subscriptions' => $subscriptions,
            ];
        });
    }
}
