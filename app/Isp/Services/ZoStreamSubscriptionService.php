<?php

namespace App\Isp\Services;

use App\Http\Controllers\New\SubscriptionController;
use App\Isp\Models\Customer;
use App\Isp\Models\Package;
use Illuminate\Http\Request;
use RuntimeException;

class ZoStreamSubscriptionService
{
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
}
