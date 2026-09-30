<?php

namespace App\Isp\Http\Controllers;

use App\Http\Controllers\CashFreeController;
use App\Http\Controllers\New\PaymentController as ZoStreamPaymentController;
use App\Isp\Models\Customer;
use App\Isp\Models\CustomerOnboarding;
use App\Isp\Models\Package;
use App\Isp\Models\Payment;
use App\Isp\Models\PaymentCheckout;
use App\Isp\Services\CustomerOnboardingService;
use App\Isp\Services\PaymentInvoicePdf;
use App\Isp\Services\RadiusService;
use App\Isp\Services\ZoStreamSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $branchId = $request->user()->isBranchOperator() ? $request->user()->branch_id : null;

        return view('isp.payments.index', [
            'payments' => Payment::with(['customer', 'operator', 'package'])
                ->when($branchId, fn ($query) => $query->whereHas('customer', fn ($query) => $query->where('branch_id', $branchId)))
                ->latest('paid_at')->paginate(20),
            'customers' => Customer::when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->with(['package:id,name,price,validity_days', 'branch:id,name,operator_percentage,ott_deduction', 'branch.packages:id'])
                ->orderBy('name')->get(['id', 'package_id', 'branch_id', 'name', 'phone', 'username']),
            'packages' => Package::where('is_active', true)->orderBy('name')->get(),
            'selectedCustomer' => $request->integer('customer'),
        ]);
    }

    public function store(Request $request, RadiusService $radius): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'package_id' => ['nullable', 'exists:packages,id'],
            'method' => ['required', Rule::in(['cash', 'upi', 'bank', 'card'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'renew' => ['nullable', 'boolean'],
        ]);
        $customer = Customer::with(['package', 'router', 'branch'])->findOrFail($data['customer_id']);
        $this->ensureCustomerAccess($request, $customer);
        $package = $this->selectedPackage($customer, $data['package_id'] ?? null);
        if ((float) $package->price <= 0) {
            return back()->withInput()->with('error', 'The selected customer does not have a payable package.');
        }
        try {
            $amounts = $this->paymentAmounts($customer, $package);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $payment = Payment::create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'operator_id' => $request->user()->id,
            'package_amount' => $amounts['package'],
            'ott_deduction' => $amounts['ott'],
            'distributable_amount' => $amounts['distributable'],
            'operator_percentage' => $amounts['operator_percentage'],
            'operator_commission' => $amounts['commission'],
            'amount' => $amounts['payable'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'paid_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);
        $syncError = $request->boolean('renew') ? $this->renewCustomer($customer, $radius, $package) : null;

        if ($syncError) {
            return back()->with('warning', 'Payment was recorded and the customer renewed locally, but RADIUS sync failed: '.$syncError.' Use the customer Sync action to retry; do not record the payment again.');
        }

        $message = $request->boolean('renew')
            ? 'Payment recorded; customer renewed and synced with RADIUS.'
            : 'Payment recorded successfully.';

        return back()->with('success', $message);
    }

    public function checkout(Request $request, ZoStreamSubscriptionService $subscriptions): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'package_id' => ['nullable', 'exists:packages,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'renew' => ['nullable', 'boolean'],
        ]);
        $customer = Customer::with(['package', 'router', 'branch'])->findOrFail($data['customer_id']);
        $this->ensureCustomerAccess($request, $customer);
        $package = $this->selectedPackage($customer, $data['package_id'] ?? null);

        try {
            $amounts = $this->paymentAmounts($customer, $package);
            $external = $subscriptions->createOrder($customer, $package);
            $order = $external['cashfree_order'];
            $checkout = PaymentCheckout::create([
                'user_id' => $request->user()->id,
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'external_order_id' => $order['order_id'],
                'gateway' => 'cashfree',
                'razorpay_key_id' => null,
                'payment_session_id' => $external['payment_session_id'],
                'package_amount' => $amounts['package'],
                'ott_deduction' => $amounts['ott'],
                'distributable_amount' => $amounts['distributable'],
                'operator_percentage' => $amounts['operator_percentage'],
                'operator_commission' => $amounts['commission'],
                'amount' => $amounts['payable'],
                'currency' => 'INR',
                'renew' => $request->boolean('renew'),
                'notes' => $data['notes'] ?? null,
                'external_response' => $external,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'checkout_id' => $checkout->id,
            'order_id' => $checkout->external_order_id,
            'payment_session_id' => $checkout->payment_session_id,
            'mode' => strtoupper((string) config('cashfree.env', 'SANDBOX')) === 'PRODUCTION'
                ? 'production'
                : 'sandbox',
            'amount' => (int) round((float) $checkout->amount * 100),
            'currency' => $checkout->currency,
            'name' => config('app.name'),
            'description' => $package->name.' renewal for '.$customer->username,
            'prefill' => [
                'name' => $customer->name,
                'contact' => $customer->phone,
            ],
        ]);
    }

    public function completeCashfree(
        Request $request,
        RadiusService $radius,
        CashFreeController $cashfree,
        ZoStreamPaymentController $zostreamPayments,
    ): JsonResponse {
        $data = $request->validate([
            'checkout_id' => ['required', 'integer', 'exists:payment_checkouts,id'],
            'order_id' => ['required', 'string', 'max:100'],
        ]);

        $checkout = PaymentCheckout::with(['customer.package', 'customer.router', 'customer.branch', 'package'])
            ->where('user_id', $request->user()->id)
            ->findOrFail($data['checkout_id']);
        $this->ensureCustomerAccess($request, $checkout->customer);
        if ($checkout->gateway !== 'cashfree' || ! hash_equals($checkout->external_order_id, $data['order_id'])) {
            return response()->json(['message' => 'The Cashfree order does not match this checkout.'], 422);
        }

        try {
            [$status, $gatewayPaymentId] = $this->verifyCashfreeCheckout($checkout, $cashfree);
            $result = $this->finalizeCashfreeCheckout(
                $checkout,
                $gatewayPaymentId,
                $status,
                $radius,
                $zostreamPayments,
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 409);
        }

        ['payment' => $payment, 'created' => $created, 'activation_error' => $activationError, 'sync_error' => $syncError] = $result;
        $message = $created ? 'Cashfree payment verified and recorded.' : 'Payment was already recorded.';
        if ($activationError) {
            session()->flash('warning', $message.' ZoStream subscription activation needs a retry: '.$activationError);
        } elseif ($syncError) {
            session()->flash('warning', $message.' Customer renewed locally, but RADIUS sync failed: '.$syncError);
        } else {
            session()->flash('success', $checkout->renew ? $message.' Customer renewed and synced with RADIUS.' : $message);
        }

        return response()->json([
            'message' => $message,
            'payment_id' => $payment?->id,
            'redirect' => route('isp.payments.index'),
        ]);
    }

    public function cashfreeWebhook(
        Request $request,
        RadiusService $radius,
        CashFreeController $cashfree,
        ZoStreamPaymentController $zostreamPayments,
        CustomerOnboardingService $customerOnboarding,
    ): JsonResponse {
        $rawBody = $request->getContent();
        $timestamp = (string) $request->header('x-webhook-timestamp', '');
        $signature = (string) $request->header('x-webhook-signature', '');
        $secret = $this->cashfreeSecret();

        if ($secret === '' || $timestamp === '' || $signature === '') {
            return response()->json(['message' => 'Cashfree webhook authentication is not configured.'], 401);
        }

        $expectedSignature = base64_encode(hash_hmac('sha256', $timestamp.$rawBody, $secret, true));
        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json(['message' => 'Invalid Cashfree webhook signature.'], 401);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid Cashfree webhook payload.'], 422);
        }

        $type = strtoupper((string) data_get($payload, 'type'));
        $orderId = (string) data_get($payload, 'data.order.order_id', '');
        if ($orderId === '') {
            return response()->json(['message' => 'Cashfree webhook order ID is missing.'], 422);
        }

        $checkout = PaymentCheckout::with(['customer.package', 'customer.router', 'customer.branch', 'package'])
            ->where('gateway', 'cashfree')
            ->where('external_order_id', $orderId)
            ->first();
        if (! $checkout) {
            $onboarding = str_starts_with($orderId, 'isp_router_')
                ? CustomerOnboarding::where('cashfree_order_id', $orderId)->first()
                : null;
            if ($onboarding) {
                $gatewayPaymentId = (string) data_get($payload, 'data.payment.cf_payment_id', '') ?: null;
                if ($type !== 'PAYMENT_SUCCESS_WEBHOOK') {
                    if (in_array($type, ['PAYMENT_FAILED_WEBHOOK', 'PAYMENT_USER_DROPPED_WEBHOOK'], true)) {
                        $customerOnboarding->markPaymentAttempt(
                            $onboarding,
                            $type === 'PAYMENT_FAILED_WEBHOOK' ? 'failed' : 'dropped',
                            $gatewayPaymentId,
                        );
                    }

                    return response()->json(['status' => 'ignored', 'type' => $type]);
                }

                try {
                    $result = $customerOnboarding->completePaidOnboarding($onboarding);
                } catch (Throwable $e) {
                    report($e);

                    return response()->json(['message' => 'Cashfree customer onboarding failed.'], 503);
                }

                return response()->json([
                    'status' => 'success',
                    'customer_id' => $result['customer']->id,
                    'already_processed' => ! $result['created'],
                    'activation_error' => $result['activation_error'],
                    'sync_error' => $result['sync_error'],
                ], $result['activation_error'] || $result['sync_error'] ? 503 : 200);
            }

            return response()->json([
                'status' => 'ignored',
                'message' => 'This Cashfree order does not belong to the ISP checkout.',
            ]);
        }

        if ($type !== 'PAYMENT_SUCCESS_WEBHOOK') {
            if (in_array($type, ['PAYMENT_FAILED_WEBHOOK', 'PAYMENT_USER_DROPPED_WEBHOOK'], true)
                && $checkout->status !== 'paid') {
                $checkout->update([
                    'status' => $type === 'PAYMENT_FAILED_WEBHOOK' ? 'failed' : 'dropped',
                    'gateway_payment_id' => (string) data_get($payload, 'data.payment.cf_payment_id', '') ?: null,
                    'external_response' => $payload,
                ]);
            }

            return response()->json(['status' => 'ignored', 'type' => $type]);
        }

        try {
            [$status, $gatewayPaymentId] = $this->verifyCashfreeCheckout($checkout, $cashfree);
            $result = $this->finalizeCashfreeCheckout(
                $checkout,
                $gatewayPaymentId,
                $status,
                $radius,
                $zostreamPayments,
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Cashfree webhook processing failed.'], 503);
        }

        return response()->json([
            'status' => 'success',
            'payment_id' => $result['payment']?->id,
            'already_processed' => ! $result['created'],
            'activation_error' => $result['activation_error'],
            'sync_error' => $result['sync_error'],
        ], $result['activation_error'] ? 503 : 200);
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        $this->ensureCustomerAccess(request(), $payment->customer);
        $payment->delete();

        return back()->with('success', 'Payment deleted.');
    }

    public function invoice(Payment $payment, PaymentInvoicePdf $invoice): Response
    {
        $payment->loadMissing('customer');
        $this->ensureCustomerAccess(request(), $payment->customer);

        $filename = sprintf('zostream-invoice-%06d.pdf', $payment->id);

        return response($invoice->render($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function verifyCashfreeCheckout(PaymentCheckout $checkout, CashFreeController $cashfree): array
    {
        $statusRequest = Request::create('/api/cash-free-payment', 'GET', [
            'order_id' => $checkout->external_order_id,
        ]);
        $statusRequest->headers->set('X-CF-Env', (string) config('cashfree.env', 'SANDBOX'));
        $statusResponse = $cashfree->checkPayment($statusRequest);
        $status = $statusResponse->getData(true);
        $gatewayOrder = data_get($status, 'data.order', []);

        if (! $statusResponse->isSuccessful() || data_get($status, 'success') !== true) {
            throw new RuntimeException('Cashfree payment is not completed yet.');
        }

        $gatewayAmount = (int) round(((float) data_get($gatewayOrder, 'order_amount', 0)) * 100);
        $expectedAmount = (int) round(((float) $checkout->amount) * 100);
        if (! hash_equals((string) $checkout->external_order_id, (string) data_get($gatewayOrder, 'order_id'))
            || $gatewayAmount !== $expectedAmount
            || strtoupper((string) data_get($gatewayOrder, 'order_currency')) !== $checkout->currency) {
            throw new RuntimeException('Cashfree returned an unexpected order amount or currency.');
        }

        $successfulPayment = collect(data_get($status, 'data.payments', []))->first(
            fn (array $payment): bool => in_array(strtoupper((string) ($payment['payment_status'] ?? '')), ['SUCCESS', 'CAPTURED', 'COMPLETED'], true)
        );
        $gatewayPaymentId = (string) ($successfulPayment['cf_payment_id'] ?? 'cashfree-'.$checkout->external_order_id);

        return [$status, $gatewayPaymentId];
    }

    private function finalizeCashfreeCheckout(
        PaymentCheckout $checkout,
        string $gatewayPaymentId,
        array $status,
        RadiusService $radius,
        ZoStreamPaymentController $zostreamPayments,
    ): array {
        [$payment, $created] = DB::transaction(function () use ($checkout, $gatewayPaymentId, $status): array {
            $locked = PaymentCheckout::lockForUpdate()->findOrFail($checkout->id);
            if ($locked->status === 'paid') {
                if (! hash_equals((string) $locked->gateway_payment_id, $gatewayPaymentId)) {
                    throw new RuntimeException('This checkout has already been completed by another payment.');
                }

                return [$locked->payment, false];
            }

            $payment = Payment::create([
                'customer_id' => $locked->customer_id,
                'package_id' => $locked->package_id,
                'operator_id' => $locked->user_id,
                'package_amount' => $locked->package_amount,
                'ott_deduction' => $locked->ott_deduction,
                'distributable_amount' => $locked->distributable_amount,
                'operator_percentage' => $locked->operator_percentage,
                'operator_commission' => $locked->operator_commission,
                'amount' => $locked->amount,
                'method' => 'cashfree',
                'reference' => $gatewayPaymentId,
                'paid_at' => now(),
                'notes' => $locked->notes,
            ]);
            $locked->update([
                'payment_id' => $payment->id,
                'status' => 'paid',
                'gateway_payment_id' => $gatewayPaymentId,
                'external_response' => $status,
                'paid_at' => now(),
            ]);

            return [$payment, true];
        });

        $activationError = null;
        try {
            $zostreamPayments->processExternalOrderPayments($checkout->external_order_id, 'cashfree');
        } catch (Throwable $e) {
            report($e);
            $activationError = $e->getMessage();
        }

        $syncError = null;
        if ($created && $checkout->renew) {
            $syncError = $this->renewCustomer($checkout->customer, $radius, $checkout->package);
        }

        return [
            'payment' => $payment,
            'created' => $created,
            'activation_error' => $activationError,
            'sync_error' => $syncError,
        ];
    }

    private function cashfreeSecret(): string
    {
        $environment = strtoupper((string) config('cashfree.env', 'SANDBOX'));
        $secret = (string) config($environment === 'PRODUCTION'
            ? 'cashfree.client_secret'
            : 'cashfree.sandbox_client_secret');

        return $secret !== '' ? $secret : (string) config('cashfree.client_secret', '');
    }

    private function ensureCustomerAccess(Request $request, ?Customer $customer): void
    {
        abort_if(! $customer, 404);
        if ($request->user()?->isBranchOperator()) {
            abort_unless($customer->branch_id === $request->user()->branch_id, 403);
        }
    }

    private function renewCustomer(Customer $customer, RadiusService $radius, ?Package $package): ?string
    {
        if (! $package) {
            return 'The package selected during checkout no longer exists.';
        }

        $base = $customer->expires_at && $customer->expires_at->isFuture() ? $customer->expires_at : today();
        $customer->setRelation('package', $package);
        $customer->update([
            'package_id' => $package->id,
            'expires_at' => $base->copy()->addDays($package->validity_days),
            'status' => 'active',
        ]);
        try {
            $radius->syncCustomer($customer);
        } catch (Throwable $e) {
            report($e);

            return $e->getMessage();
        }

        return null;
    }

    private function paymentAmounts(Customer $customer, Package $package): array
    {
        $packageAmount = round((float) $package->price, 2);
        $ottDeduction = round(max(0, (float) ($customer->branch?->ott_deduction ?? 0)), 2);
        $operatorPercentage = round(min(100, max(0, (float) ($customer->branch?->operator_percentage
            ?? config('services.zostream_subscription.operator_percentage', 20)))), 2);
        $distributableAmount = round($packageAmount - $ottDeduction, 2);
        $commission = round($distributableAmount * ($operatorPercentage / 100), 2);
        $wifiShare = round($distributableAmount - $commission, 2);
        $payableAmount = round($wifiShare + $ottDeduction, 2);

        if ($packageAmount <= 0 || $payableAmount <= 0) {
            throw new RuntimeException('The package amount must be greater than the OTT deduction.');
        }

        return [
            'package' => $packageAmount,
            'ott' => $ottDeduction,
            'distributable' => $distributableAmount,
            'operator_percentage' => $operatorPercentage,
            'commission' => $commission,
            'wifi_share' => $wifiShare,
            'payable' => $payableAmount,
        ];
    }

    private function selectedPackage(Customer $customer, mixed $packageId): Package
    {
        $packageId = $packageId ?: $customer->package_id;
        $package = Package::where('is_active', true)->find($packageId);
        if (! $package) {
            throw ValidationException::withMessages([
                'package_id' => 'Select an active package for this payment.',
            ]);
        }

        $customer->loadMissing('branch.packages');
        if ($customer->branch && $customer->branch->packages->isNotEmpty()
            && ! $customer->branch->packages->contains('id', $package->id)) {
            throw ValidationException::withMessages([
                'package_id' => 'The selected package is not available for this customer branch.',
            ]);
        }

        return $package;
    }
}
