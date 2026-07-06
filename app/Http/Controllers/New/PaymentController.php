<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\CashFreeController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\NewStreamController;
use App\Http\Controllers\PhonePeSdkV2Controller;
use App\Http\Controllers\RazorpayController;
use App\Http\Controllers\SubscriptionController as LegacySubscriptionController;
use App\Models\New\Plan;
use App\Models\New\Subscription;
use App\Models\PPVPaymentModel;
use App\Models\New\PaymentHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PaymentController extends Controller
{
    private $merchantId = 'M221AEW7ARW15';
    private $saltKey = '1d8c7b88-710d-4c48-a70a-cdd08c8cabac';

    protected $subscriptionController;
    protected $cashfreeController;
    protected $PhonepePaymentController;
    protected $razorpayController;
    protected $streamEventController;

    public function __construct(
        SubscriptionController $subscriptionController,
        CashFreeController $cashFreeController,
        PhonePeSdkV2Controller $phonepePaymentController,
        RazorpayController $razorpayController,
        NewStreamController $streamEventController
    ) {


        $this->subscriptionController = $subscriptionController;
        $this->cashfreeController = $cashFreeController;
        $this->PhonepePaymentController = $phonepePaymentController;
        $this->razorpayController = $razorpayController;
        $this->streamEventController = $streamEventController;
    }

    public function processUserPayments(Request $request)
    {
        $request->validate([
            'device_id' => 'required|string',
            'device_type' => 'required|string',
            'user_id' => 'required|string',
        ]);

        $uid = $request->query('user_id');
        $deviceId = $request->query('device_id');
        $deviceType = $request->query('device_type');

        $pendingPayments = PaymentHistory::where('user_id', $uid)
            ->where('status', 'pending')
            ->get()
            ->all();

        if (empty($pendingPayments)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No pending payments found'
            ]);
        }

        $successCount = 0;
        $failureCount = 0;
        $pendingCount = 0;

        foreach ($pendingPayments as $payment) {

            DB::beginTransaction();

            try {

                $merchantOrderId = $payment->transaction_id;

                // 🔹 1️⃣ Check gateway status
                if (strtolower($payment->payment_gateway) === 'phonepe') {
                    $h = strtolower(trim((string) $request->header('X-PP-Env', 'production')));
                    $phonepeReq = new Request(['X-PP-Env' => $h]);
                    $paymentResponse = $this->checkPaymentStatus($phonepeReq, $merchantOrderId);

                } elseif (strtolower($payment->payment_gateway) === 'razorpay') {
                    $h = strtolower(trim((string) $request->header('X-RZ-Env', 'production')));
                    $razorReq = new Request(['X-RZ-Env' => $h]);
                    $razorResponse = $this->razorpayController
                        ->checkPaymentStatus($razorReq, $merchantOrderId);

                    $paymentResponse = json_decode($razorResponse->getContent(), true);

                } else {
                    $cashfreeReq = new Request(['order_id' => $merchantOrderId]);
                    $cashfreeResponse = $this->cashfreeController->checkPayment($cashfreeReq);
                    $paymentResponse = json_decode($cashfreeResponse->getContent(), true);
                }

                $paymentSuccess =
                    (isset($paymentResponse['success']) && $paymentResponse['success'] === true)
                    || (isset($paymentResponse['code']) && $paymentResponse['code'] === 'PAYMENT_SUCCESS')
                    || (isset($paymentResponse['data']['state']) && $paymentResponse['data']['state'] === 'COMPLETED');

                $gatewayState = strtoupper((string) ($paymentResponse['data']['state'] ?? ''));
                $gatewayCode = strtoupper((string) ($paymentResponse['code'] ?? ''));
                $paymentPending = in_array($gatewayState, ['PENDING', 'CREATED', 'ATTEMPTED'], true)
                    || in_array($gatewayCode, ['PENDING', 'CREATED', 'ATTEMPTED'], true);

                if ($paymentSuccess) {

                    // 🔹 If subscription → calculate expiry
                    if ($payment->movie_id === null) {

                        $plan = Plan::find($payment->plan_id);

                        if (!$plan) {
                            return response()->json([
                                'status' => 'error',
                                'message' => 'Invalid plan ID'
                            ], 404);
                        }

                        $startAt = now();
                        $subscription = Subscription::activeForUserAndDeviceType($uid, $plan->device_type)
                            ->lockForUpdate()
                            ->first();
                        $endAt = $subscription && $subscription->end_at && $subscription->end_at->isFuture()
                            ? $subscription->end_at->copy()->addDays($plan->duration_days)
                            : $startAt->copy()->addDays($plan->duration_days);

                        if ($subscription) {
                            $updates = [
                                'plan_id' => $plan->id,
                                'end_at' => $endAt,
                                'is_active' => true,
                            ];

                            if (!$subscription->end_at || $subscription->end_at->isPast()) {
                                $updates['start_at'] = $startAt;
                            }

                            $subscription->update($updates);
                        } else {
                            $subscription = Subscription::create([
                                'user_id' => $uid,
                                'plan_id' => $plan->id,
                                'start_at' => $startAt,
                                'end_at' => $endAt,
                                'is_active' => true,
                            ]);
                        }

                        // 🔹 Update payment (single update block)
                        $payment->update([
                            'subscription_id' => $subscription->id,
                            'status' => 'success',
                        ]);

                        $fakeRequest = new Request([
                            'user_id' => $uid,
                            'device_id' => $deviceId,
                            'subscription_id' => $subscription->id,
                            'device_type' => $deviceType
                        ]);

                        $this->streamEventController->renew($fakeRequest);
                    }

                    // 🔹 If PPV → grant access
                    if ($payment->movie_id) {
                        $meta = is_array($payment->meta) ? $payment->meta : [];
                        $meta['device_token'] = $meta['device_token'] ?? $deviceId;
                        $meta['device_type'] = $meta['device_type'] ?? strtolower(trim((string) $deviceType));

                        $payment->update([
                            'status' => 'success',
                            'payment_date' => now(),
                            'expiry_date' => Carbon::now()->addDays(7), // 7-day access for PPV
                            'meta' => $meta,
                        ]);
                    }

                    $successCount++;

                    DB::commit();
                } elseif ($paymentPending) {

                    // Razorpay can report pending briefly after its success callback.
                    // Keep the record retryable instead of turning a valid payment into failed.
                    DB::commit();
                    $pendingCount++;

                } else {

                    $payment->update(['status' => 'failed']);
                    DB::commit();
                    $failureCount++;
                }

            } catch (\Exception $e) {

                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment processing failed',
                    'error' => $e->getMessage(), // 🔥 MAIN ERROR
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ], 500);

            }
        }

        $status = $successCount > 0 ? 'success' : ($pendingCount > 0 ? 'pending' : 'error');

        return response()->json([
            'status' => $status,
            'message' => "Processed payments. Success: $successCount, Pending: $pendingCount, Failures: $failureCount",
        ], 200);
    }

    public function razorpayWebhook(Request $request)
    {
        $secret = (string) config('razorpay.webhook_secret', '');

        if ($secret === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Razorpay webhook secret is not configured',
            ], 500);
        }

        $payload = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature', '');
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if ($signature === '' || !hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid Razorpay webhook signature',
            ], 400);
        }

        $event = (string) $request->input('event', '');
        $successEvents = ['payment.captured', 'order.paid'];
        $failedEvents = ['payment.failed'];

        if (!in_array($event, array_merge($successEvents, $failedEvents), true)) {
            return response()->json([
                'status' => 'ignored',
                'message' => 'Razorpay event ignored',
                'event' => $event,
            ]);
        }

        $orderId = $this->razorpayWebhookOrderId($request);

        if (!$orderId) {
            return response()->json([
                'status' => 'ignored',
                'message' => 'Razorpay order id missing in webhook payload',
                'event' => $event,
            ]);
        }

        $payment = PaymentHistory::where('transaction_id', $orderId)
            ->where('payment_gateway', 'razorpay')
            ->latest()
            ->first();

        if (!$payment) {
            Log::info('Razorpay webhook payment record not found', [
                'event' => $event,
                'order_id' => $orderId,
            ]);

            return response()->json([
                'status' => 'ignored',
                'message' => 'Payment record not found',
                'order_id' => $orderId,
            ]);
        }

        $meta = is_array($payment->meta) ? $payment->meta : [];
        $deviceId = $meta['device_token'] ?? $meta['device_id'] ?? null;
        $deviceType = $meta['device_type'] ?? $payment->device_type ?? null;

        DB::beginTransaction();

        try {
            $payment = PaymentHistory::whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->status === 'success') {
                DB::commit();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment already processed',
                    'event' => $event,
                    'order_id' => $orderId,
                    'already_processed' => true,
                ]);
            }

            if (in_array($event, $failedEvents, true)) {
                $payment->update(['status' => 'failed']);
                DB::commit();

                return response()->json([
                    'status' => 'failed',
                    'message' => 'Webhook payment failed and history updated',
                    'event' => $event,
                    'order_id' => $orderId,
                    'already_processed' => false,
                ]);
            }

            if (in_array($event, $successEvents, true)) {
                if ($payment->movie_id === null) {
                    $this->activateSubscriptionPayment($payment, $deviceId, $deviceType);
                } else {
                    $this->activatePpvPayment($payment, $deviceId, $deviceType);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Webhook payment processed successfully',
                'event' => $event,
                'order_id' => $orderId,
                'already_processed' => false,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Razorpay webhook processing failed', [
                'event' => $event,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Webhook payment processing failed',
            ], 500);
        }
    }

    private function activateSubscriptionPayment(
        PaymentHistory $payment,
        ?string $deviceId = null,
        ?string $deviceType = null
    ): void {
        $uid = (string) $payment->user_id;
        $plan = Plan::find($payment->plan_id);

        if (!$plan) {
            throw new \RuntimeException('Invalid plan ID');
        }

        $startAt = now();
        $subscription = Subscription::activeForUserAndDeviceType($uid, $plan->device_type)
            ->lockForUpdate()
            ->first();
        $endAt = $startAt->copy()->addDays($plan->duration_days);

        if ($subscription) {
            $updates = [
                'plan_id' => $plan->id,
                'end_at' => $endAt,
                'is_active' => true,
            ];

            if (!$subscription->end_at || $subscription->end_at->isPast()) {
                $updates['start_at'] = $startAt;
            }

            $subscription->update($updates);
        } else {
            $subscription = Subscription::create([
                'user_id' => $uid,
                'plan_id' => $plan->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'is_active' => true,
            ]);
        }

        $payment->update([
            'subscription_id' => $subscription->id,
            'status' => 'success',
        ]);

        $resolvedDeviceType = $deviceType ?: $payment->device_type ?: $plan->device_type;

        if ($deviceId && $resolvedDeviceType) {
            $fakeRequest = new Request([
                'user_id' => $uid,
                'device_id' => $deviceId,
                'subscription_id' => $subscription->id,
                'device_type' => $resolvedDeviceType,
            ]);

            $this->streamEventController->renew($fakeRequest);
        }
    }

    private function activatePpvPayment(
        PaymentHistory $payment,
        ?string $deviceId = null,
        ?string $deviceType = null
    ): void {
        $meta = is_array($payment->meta) ? $payment->meta : [];
        $meta['device_token'] = $meta['device_token'] ?? $deviceId;
        $meta['device_type'] = $meta['device_type'] ?? strtolower(trim((string) $deviceType));

        $payment->update([
            'status' => 'success',
            'payment_date' => now(),
            'expiry_date' => Carbon::now()->addDays(7),
            'meta' => $meta,
        ]);
    }

    private function razorpayWebhookOrderId(Request $request): ?string
    {
        $orderId = $request->input('payload.payment.entity.order_id')
            ?: $request->input('payload.order.entity.id');

        $orderId = is_string($orderId) ? trim($orderId) : '';

        return $orderId !== '' ? $orderId : null;
    }

    public function createRazorpaySubscriptionOrder(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:n_plans,id',
            'currency' => 'nullable|string|size:3',
        ]);

        $authUserId = (string) $request->input('auth_user_id', '');
        if ($authUserId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Authenticated user is required',
            ], 401);
        }

        $plan = Plan::where('id', $validated['plan_id'])
            ->where('is_active', true)
            ->first();

        if (!$plan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or inactive plan selected',
            ], 404);
        }

        $amount = (float) $plan->price;
        if ($amount <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Selected plan has an invalid amount',
            ], 422);
        }

        $currency = strtoupper($validated['currency'] ?? 'INR');
        $receipt = substr('sub_' . $plan->id . '_' . now()->timestamp, 0, 40);
        $razorpayRequest = new Request([
            'amount' => $amount,
            'currency' => $currency,
            'receipt' => $receipt,
            'capture' => true,
            'notes' => [
                'user_id' => $authUserId,
                'plan_id' => (string) $plan->id,
                'payment_for' => 'subscription',
            ],
        ]);

        $razorpayRequest->headers->set(
            'X-RZ-Env',
            $this->razorpayEnv($request)
        );

        $razorpayResponse = $this->razorpayController->createOrder($razorpayRequest);
        $razorpayData = json_decode($razorpayResponse->getContent(), true);

        if (!$razorpayResponse->isSuccessful() || !($razorpayData['ok'] ?? false)) {
            return response()->json([
                'status' => 'error',
                'message' => $razorpayData['message'] ?? 'Failed to create Razorpay order',
                'error' => $razorpayData,
            ], $razorpayResponse->getStatusCode() >= 400 ? $razorpayResponse->getStatusCode() : 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Razorpay order created',
            'data' => [
                'key_id' => $this->razorpayKeyId($request),
                'order' => $razorpayData['order'],
                'plan' => [
                    'id' => $plan->id,
                    'name' => $plan->name ?? $plan->plan ?? null,
                    'amount' => $amount,
                    'currency' => $currency,
                ],
            ],
        ], 201);
    }

    public function verifyRazorpaySubscriptionPayment(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:n_plans,id',
            'razorpay_order_id' => 'required|string|max:255',
            'razorpay_payment_id' => 'required|string|max:255',
            'razorpay_signature' => 'required|string|max:255',
            'currency' => 'nullable|string|size:3',
            'target_device_token' => 'nullable|string|max:255',
        ]);

        $authUserId = (string) $request->input('auth_user_id', '');
        if ($authUserId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Authenticated user is required',
            ], 401);
        }

        $existingPayment = PaymentHistory::where('transaction_id', $validated['razorpay_order_id'])
            ->where('status', 'success')
            ->first();

        if ($existingPayment) {
            if (
                (string) $existingPayment->user_id !== $authUserId ||
                (int) $existingPayment->plan_id !== (int) $validated['plan_id']
            ) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment order does not match this subscription request',
                ], 403);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payment already verified',
                'data' => [
                    'payment_history' => $existingPayment,
                    'subscription' => $existingPayment->subscription,
                ],
            ], 200);
        }

        $keySecret = $this->razorpayKeySecret($request);
        if ($keySecret === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Razorpay secret is not configured',
            ], 500);
        }

        $expectedSignature = hash_hmac(
            'sha256',
            $validated['razorpay_order_id'] . '|' . $validated['razorpay_payment_id'],
            $keySecret
        );

        if (!hash_equals($expectedSignature, $validated['razorpay_signature'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid Razorpay payment signature',
            ], 422);
        }

        $statusRequest = new Request();
        $statusRequest->headers->set(
            'X-RZ-Env',
            $this->razorpayEnv($request)
        );
        $statusResponse = $this->razorpayController
            ->checkPaymentStatus($statusRequest, $validated['razorpay_order_id']);
        $statusData = json_decode($statusResponse->getContent(), true);

        if (!$statusResponse->isSuccessful() || !($statusData['success'] ?? false)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Razorpay payment is not completed',
                'error' => $statusData,
            ], 409);
        }

        $plan = Plan::where('id', $validated['plan_id'])
            ->where('is_active', true)
            ->first();

        if (!$plan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or inactive plan selected',
            ], 404);
        }

        $subscriptionRequest = new Request([
            'user_id' => $authUserId,
            'plan_id' => $plan->id,
            'amount' => $plan->price,
            'start_at' => now()->toDateString(),
            'currency' => strtoupper($validated['currency'] ?? 'INR'),
            'payment_method' => 'checkout',
            'payment_gateway' => 'razorpay',
            'transaction_id' => $validated['razorpay_order_id'],
            'payment_type' => 'new',
            'status' => 'success',
            'target_device_token' => $validated['target_device_token'] ?? null,
        ]);

        $subscriptionResponse = $this->subscriptionController
            ->createSubscriptionWithPayment($subscriptionRequest);
        $subscriptionData = json_decode($subscriptionResponse->getContent(), true);

        if (!$subscriptionResponse->isSuccessful() || ($subscriptionData['status'] ?? '') !== 'success') {
            return response()->json([
                'status' => 'error',
                'message' => $subscriptionData['message'] ?? 'Failed to activate subscription',
                'error' => $subscriptionData,
            ], $subscriptionResponse->getStatusCode() >= 400 ? $subscriptionResponse->getStatusCode() : 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Payment verified and subscription activated',
            'data' => $subscriptionData['data'] ?? null,
        ], 200);
    }

    private function checkPaymentStatus($phonepeReq, $merchantOrderId)
    {
        $phonepeResponse = $this->PhonepePaymentController->getOrderStatus($phonepeReq, $merchantOrderId);

        // Decode JSON into array
        $paymentResponse = json_decode($phonepeResponse->getContent(), true);

        return $paymentResponse; // ✅ return as array
    }

    private function razorpayEnv(Request $request): string
    {
        $raw = strtoupper(trim((string) $request->header('X-RZ-Env', '')));

        if (in_array($raw, ['PRODUCTION', 'SANDBOX'], true)) {
            return $raw;
        }

        $configured = strtoupper(trim((string) config('razorpay.env', 'PRODUCTION')));

        return in_array($configured, ['PRODUCTION', 'SANDBOX'], true)
            ? $configured
            : 'PRODUCTION';
    }

    private function razorpayKeyId(Request $request): string
    {
        return $this->razorpayCredential($request, 'key_id');
    }

    private function razorpayKeySecret(Request $request): string
    {
        return $this->razorpayCredential($request, 'key_secret');
    }

    private function razorpayCredential(Request $request, string $key): string
    {
        $env = $this->razorpayEnv($request);
        $mode = $env === 'PRODUCTION' ? 'live' : 'test';
        $value = (string) config("razorpay.$mode.$key", '');

        if ($value === '') {
            $value = (string) config("razorpay.live.$key", '');
        }

        return $value;
    }

}
