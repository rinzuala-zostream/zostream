<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Http\Controllers\RazorpayController;
use App\Models\AdPayment;
use App\Models\AdSubmission;
use App\Services\AdBillingService;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AdPaymentController extends Controller
{
    public function __construct(
        private readonly RazorpayController $razorpay,
        private readonly AdBillingService $billing,
    ) {}

    public function show(string $token)
    {
        $submission = $this->submission($token)->load('campaign.invoices');
        $invoice = $submission->campaign?->invoices->sortByDesc('id')->first();

        if (! $invoice) {
            return V4Response::error('AD_INVOICE_NOT_READY', 'The invoice is not ready for payment.', 409);
        }

        // A payment can be captured even if the browser closes or a legacy
        // mirror write temporarily fails. Visiting the WhatsApp payment link
        // must therefore recover a paid pending campaign, without attempting
        // to charge the advertiser a second time.
        $activationPending = $this->reconcilePaidInvoice($invoice, $submission);
        $submission->load('campaign.invoices');
        $invoice = $submission->campaign?->invoices->sortByDesc('id')->first();

        return V4Response::success([
            'reference_no' => $submission->reference_no,
            'status' => $submission->status,
            'business_name' => $submission->business_name,
            'contact_name' => $submission->contact_name,
            'contact_email' => $submission->contact_email,
            'ads_name' => $submission->ads_name,
            'type' => $submission->type,
            'placement_code' => $submission->placement_code,
            'billing_model' => $submission->billing_model,
            'quoted_rate' => (float) $submission->quoted_rate,
            'quoted_amount' => (float) $submission->quoted_amount,
            'currency' => $submission->currency,
            'requested_period_days' => $submission->requested_period_days,
            'campaign' => [
                'status' => $submission->campaign->status,
                'activation_pending' => $activationPending,
                'invoice' => [
                    'invoice_no' => $invoice->invoice_no,
                    'status' => $invoice->status,
                    'total' => (float) $invoice->total,
                    'currency' => $invoice->currency,
                ],
            ],
            'events' => [],
        ]);
    }

    public function createOrder(Request $request, string $token)
    {
        $submission = $this->submission($token)->load('campaign.invoices');
        $invoice = $submission->campaign?->invoices->sortByDesc('id')->first();
        if (! $invoice) {
            return V4Response::error('AD_INVOICE_NOT_READY', 'The invoice is not ready for payment.', 409);
        }
        if ($invoice->status === 'paid') {
            $activationPending = $this->reconcilePaidInvoice($invoice, $submission);
            $campaignStatus = $submission->campaign->fresh()?->status;

            return V4Response::success([
                'invoice_status' => 'paid',
                'campaign_status' => $campaignStatus,
                'activation_pending' => $activationPending,
            ], 'This invoice is already paid.');
        }

        $gatewayRequest = new Request([
            'amount' => (float) $invoice->total,
            'currency' => $invoice->currency,
            'receipt' => $invoice->invoice_no,
            'capture' => true,
            'notes' => ['ad_invoice_id' => (string) $invoice->id, 'reference_no' => $submission->reference_no],
            'env' => config('razorpay.env', 'SANDBOX'),
        ]);
        $gatewayRequest->headers->set('X-RZ-Env', (string) config('razorpay.env', 'SANDBOX'));
        $response = $this->razorpay->createOrder($gatewayRequest);
        $payload = $response->getData(true);
        if (! $response->isSuccessful() || ! ($payload['ok'] ?? false)) {
            return V4Response::error('AD_PAYMENT_ORDER_FAILED', $payload['message'] ?? 'Payment order creation failed.', 502);
        }

        AdPayment::updateOrCreate(
            ['gateway' => 'razorpay', 'gateway_order_id' => $payload['order']['id']],
            [
                'advertiser_id' => $invoice->advertiser_id,
                'invoice_id' => $invoice->id,
                'amount' => $invoice->total,
                'currency' => $invoice->currency,
                'payment_method' => 'razorpay',
                'status' => 'pending',
                'metadata' => ['order' => $payload['order']],
            ]
        );

        return V4Response::success([
            'key_id' => $payload['key_id'],
            'order' => $payload['order'],
            'invoice_no' => $invoice->invoice_no,
            'business_name' => $submission->business_name,
            'contact_name' => $submission->contact_name,
            'contact_email' => $submission->contact_email,
            'contact_phone' => $submission->contact_phone,
        ]);
    }

    public function verify(Request $request, string $token)
    {
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:255'],
            'razorpay_payment_id' => ['required', 'string', 'max:255'],
            'razorpay_signature' => ['required', 'string', 'max:255'],
        ]);
        $submission = $this->submission($token)->load('campaign.invoices');
        $invoice = $submission->campaign?->invoices->sortByDesc('id')->first();
        if (! $invoice) {
            return V4Response::error('AD_INVOICE_NOT_READY', 'The invoice is not ready for payment.', 409);
        }
        $payment = AdPayment::where('invoice_id', $invoice->id)
            ->where('gateway', 'razorpay')
            ->where('gateway_order_id', $data['razorpay_order_id'])
            ->firstOrFail();
        $secret = $this->razorpaySecret();
        if ($secret === '') {
            return V4Response::error('AD_PAYMENT_NOT_CONFIGURED', 'Razorpay is not configured.', 503);
        }
        $expected = hash_hmac('sha256', $data['razorpay_order_id'].'|'.$data['razorpay_payment_id'], $secret);
        if (! hash_equals($expected, $data['razorpay_signature'])) {
            return V4Response::error('AD_PAYMENT_SIGNATURE_INVALID', 'Payment signature verification failed.', 422);
        }

        try {
            $updated = $this->billing->markPaid($invoice, [
                'amount' => $payment->amount,
                'payment_method' => 'razorpay',
                'gateway' => 'razorpay',
                'gateway_order_id' => $data['razorpay_order_id'],
                'gateway_payment_id' => $data['razorpay_payment_id'],
                'metadata' => $payment->metadata,
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Ad Razorpay payment was captured but campaign activation failed.', [
                'invoice_id' => $invoice->id,
                'order_id' => $data['razorpay_order_id'],
                'payment_id' => $data['razorpay_payment_id'],
                'exception' => $exception,
            ]);

            return V4Response::error(
                'AD_PAYMENT_ACTIVATION_FAILED',
                'Payment was received, but the campaign could not be activated yet. Please contact support with your payment ID.',
                409,
            );
        }

        // The billing service reloads the relation, but use null-safe access
        // here so a successful payment response itself can never become a 500.
        return V4Response::success([
            'invoice' => $updated,
            'campaign_status' => $updated->campaign?->status,
        ], 'Payment verified and campaign activated.');
    }

    public function webhook(Request $request)
    {
        $secret = (string) config('razorpay.webhook_secret', '');
        $signature = (string) $request->header('X-Razorpay-Signature', '');
        if ($secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return V4Response::error('AD_WEBHOOK_SIGNATURE_INVALID', 'Invalid Razorpay webhook signature.', 400);
        }

        $event = (string) $request->input('event');
        $orderId = (string) (
            $request->input('payload.payment.entity.order_id')
            ?: $request->input('payload.order.entity.id')
            ?: ''
        );
        $payment = AdPayment::where('gateway', 'razorpay')->where('gateway_order_id', $orderId)->first();
        if (! $payment) {
            return V4Response::success(null, 'Ad payment does not belong to this system.');
        }

        if ($event === 'payment.failed') {
            $payment->update(['status' => 'failed', 'metadata' => $request->input('payload')]);

            return V4Response::success(null, 'Failed ad payment recorded.');
        }
        if (! in_array($event, ['payment.captured', 'order.paid'], true)) {
            return V4Response::success(null, 'Webhook event ignored.');
        }

        $gatewayPaymentId = (string) $request->input('payload.payment.entity.id', '');
        $updated = $this->billing->markPaid($payment->invoice, [
            'amount' => $payment->amount,
            'payment_method' => 'razorpay',
            'gateway' => 'razorpay',
            'gateway_order_id' => $orderId,
            'gateway_payment_id' => $gatewayPaymentId ?: null,
            'metadata' => $request->input('payload'),
        ]);

        return V4Response::success(['invoice_no' => $updated->invoice_no], 'Ad payment processed.');
    }

    private function submission(string $token): AdSubmission
    {
        return AdSubmission::where('public_token_hash', hash('sha256', strlen($token) === 48 ? $token : 'invalid'))
            ->firstOrFail();
    }

    private function razorpaySecret(): string
    {
        $mode = strtoupper((string) config('razorpay.env', 'SANDBOX')) === 'PRODUCTION' ? 'live' : 'test';

        return (string) config("razorpay.{$mode}.key_secret", '');
    }

    private function reconcilePaidInvoice(\App\Models\AdInvoice $invoice, AdSubmission $submission): bool
    {
        $campaign = $submission->campaign;
        if ($invoice->status !== 'paid' || ! $campaign || ! in_array($campaign->status, ['pending_payment', 'approved', 'scheduled'], true)) {
            return false;
        }

        try {
            $this->billing->activatePaidCampaign($campaign);

            return false;
        } catch (\Throwable $exception) {
            // Do not turn a valid payment into an error state. The next page
            // load, webhook, or admin-dashboard visit will retry this action.
            Log::error('Could not reconcile a paid ad campaign from the payment page.', [
                'invoice_id' => $invoice->id,
                'campaign_id' => $campaign->id,
                'exception' => $exception,
            ]);

            return true;
        }
    }
}
