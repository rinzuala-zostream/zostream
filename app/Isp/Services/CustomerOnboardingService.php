<?php

namespace App\Isp\Services;

use App\Http\Controllers\CashFreeController;
use App\Isp\Models\Customer;
use App\Isp\Models\CustomerOnboarding;
use App\Isp\Models\CustomerRouterPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CustomerOnboardingService
{
    public function __construct(
        private readonly CashFreeController $cashfree,
        private readonly RadiusService $radius,
        private readonly ZoStreamSubscriptionService $subscriptions,
    ) {}

    public function createCashfreeOnboarding(
        array $customerData,
        float $routerAmount,
        ?string $notes,
        ?int $operatorId,
    ): CustomerOnboarding {
        $phone = substr(preg_replace('/\D+/', '', (string) $customerData['phone']) ?: '', -10);
        $onboarding = CustomerOnboarding::create([
            'operator_id' => $operatorId,
            'customer_payload' => $customerData,
            'username' => $customerData['username'],
            'aadhaar_front_path' => $customerData['aadhaar_front_path'] ?? null,
            'aadhaar_back_path' => $customerData['aadhaar_back_path'] ?? null,
            'aadhaar_qr_verified_at' => $customerData['aadhaar_qr_verified_at'],
            'router_amount' => $routerAmount,
            'notes' => $notes,
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);
        $orderId = substr('isp_router_'.$onboarding->id.'_'.Str::lower(Str::random(10)), 0, 45);
        $request = new Request([
            'order_id' => $orderId,
            'order_amount' => $routerAmount,
            'order_currency' => 'INR',
            'customer_details' => [
                'customer_id' => 'isp_onboarding_'.$onboarding->id,
                'customer_phone' => $phone,
                'customer_name' => (string) $customerData['name'],
            ],
            'order_meta' => [
                'notify_url' => route('isp.cashfree.webhook'),
            ],
            'order_note' => 'ISP new router payment',
        ]);
        $request->headers->set('X-CF-Env', (string) config('cashfree.env', 'SANDBOX'));
        $response = $this->cashfree->createOrder($request);
        $payload = $response->getData(true);

        if (! $response->isSuccessful()
            || data_get($payload, 'status') !== 'success'
            || blank(data_get($payload, 'data.payment_session_id'))) {
            $this->deletePendingFiles($onboarding);
            $onboarding->delete();

            throw new RuntimeException((string) (data_get($payload, 'message') ?: 'Cashfree router payment order could not be created.'));
        }

        $onboarding->update([
            'cashfree_order_id' => (string) data_get($payload, 'data.order_id', $orderId),
            'payment_session_id' => (string) data_get($payload, 'data.payment_session_id'),
        ]);

        return $onboarding->fresh();
    }

    public function createWithoutPayment(
        array $customerData,
        string $routerCondition,
        float $routerAmount,
        ?string $notes,
        ?int $operatorId,
    ): array {
        $customer = DB::transaction(function () use ($customerData, $routerCondition, $routerAmount, $notes, $operatorId): Customer {
            $customer = Customer::withoutEvents(fn (): Customer => Customer::create($customerData));
            CustomerRouterPayment::create([
                'customer_id' => $customer->id,
                'operator_id' => $operatorId,
                'router_condition' => $routerCondition,
                'amount' => $routerCondition === 'new' ? $routerAmount : 0,
                'status' => $routerCondition === 'new' ? 'unpaid' : 'not_required',
                'notes' => $routerCondition === 'new' ? $notes : null,
            ]);

            return $customer;
        });

        return $this->finishCustomer($customer);
    }

    public function completePaidOnboarding(CustomerOnboarding $onboarding): array
    {
        [$gatewayPaymentId, $gatewayStatus] = $this->verifyPayment($onboarding);

        $result = DB::transaction(function () use ($onboarding, $gatewayPaymentId): array {
            $locked = CustomerOnboarding::query()->whereKey($onboarding->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'completed' && $locked->customer_id) {
                return ['customer' => Customer::findOrFail($locked->customer_id), 'created' => false, 'onboarding' => $locked];
            }

            $customerData = $locked->customer_payload;
            $customer = Customer::withoutEvents(fn (): Customer => Customer::create($customerData));
            CustomerRouterPayment::create([
                'customer_id' => $customer->id,
                'operator_id' => $locked->operator_id,
                'router_condition' => 'new',
                'amount' => $locked->router_amount,
                'status' => 'paid',
                'method' => 'cashfree',
                'gateway_order_id' => $locked->cashfree_order_id,
                'gateway_payment_id' => $gatewayPaymentId,
                'notes' => $locked->notes,
                'paid_at' => now(),
            ]);
            $locked->update([
                'status' => 'completed',
                'customer_id' => $customer->id,
                'gateway_payment_id' => $gatewayPaymentId,
                'completed_at' => now(),
            ]);

            return ['customer' => $customer, 'created' => true, 'onboarding' => $locked];
        });

        if (! $result['created']) {
            if ($result['onboarding']->sync_error || $result['onboarding']->activation_error) {
                $finished = $this->finishCustomer($result['customer']);
                $result['onboarding']->update([
                    'sync_error' => $finished['sync_error'],
                    'activation_error' => $finished['activation_error'],
                ]);

                return $finished + ['created' => false, 'gateway_status' => $gatewayStatus];
            }

            return [
                'customer' => $result['customer'],
                'created' => false,
                'sync_error' => $result['onboarding']->sync_error,
                'activation_error' => $result['onboarding']->activation_error,
                'gateway_status' => $gatewayStatus,
            ];
        }

        $finished = $this->finishCustomer($result['customer']);
        $result['onboarding']->update([
            'sync_error' => $finished['sync_error'],
            'activation_error' => $finished['activation_error'],
        ]);

        return $finished + ['created' => true, 'gateway_status' => $gatewayStatus];
    }

    public function markPaymentAttempt(CustomerOnboarding $onboarding, string $status, ?string $gatewayPaymentId): void
    {
        if ($onboarding->status === 'completed') {
            return;
        }

        $onboarding->update([
            'status' => $status,
            'gateway_payment_id' => $gatewayPaymentId,
        ]);
    }

    private function verifyPayment(CustomerOnboarding $onboarding): array
    {
        if (blank($onboarding->cashfree_order_id)) {
            throw new RuntimeException('Cashfree router payment order is missing.');
        }

        $request = Request::create('/api/cash-free-payment', 'GET', [
            'order_id' => $onboarding->cashfree_order_id,
        ]);
        $request->headers->set('X-CF-Env', (string) config('cashfree.env', 'SANDBOX'));
        $response = $this->cashfree->checkPayment($request);
        $status = $response->getData(true);
        $order = data_get($status, 'data.order', []);

        if (! $response->isSuccessful() || data_get($status, 'success') !== true) {
            throw new RuntimeException('Cashfree router payment is not completed yet.');
        }

        if (! hash_equals((string) $onboarding->cashfree_order_id, (string) data_get($order, 'order_id'))
            || (int) round(((float) data_get($order, 'order_amount', 0)) * 100) !== (int) round(((float) $onboarding->router_amount) * 100)
            || strtoupper((string) data_get($order, 'order_currency')) !== 'INR') {
            throw new RuntimeException('Cashfree returned an unexpected router payment amount or currency.');
        }

        $payment = collect(data_get($status, 'data.payments', []))->first(
            fn (array $item): bool => in_array(strtoupper((string) ($item['payment_status'] ?? '')), ['SUCCESS', 'CAPTURED', 'COMPLETED'], true)
        );

        return [(string) ($payment['cf_payment_id'] ?? 'cashfree-'.$onboarding->cashfree_order_id), $status];
    }

    private function finishCustomer(Customer $customer): array
    {
        $syncError = null;
        $activationError = null;

        try {
            $this->radius->syncCustomer($customer);
        } catch (Throwable $e) {
            report($e);
            $syncError = $e->getMessage();
        }

        try {
            $this->subscriptions->activateComplimentaryAccess($customer->fresh());
        } catch (Throwable $e) {
            report($e);
            $activationError = $e->getMessage();
        }

        return [
            'customer' => $customer,
            'sync_error' => $syncError,
            'activation_error' => $activationError,
        ];
    }

    private function deletePendingFiles(CustomerOnboarding $onboarding): void
    {
        Storage::disk('local')->delete(array_filter([
            $onboarding->aadhaar_front_path,
            $onboarding->aadhaar_back_path,
        ]));
    }
}
