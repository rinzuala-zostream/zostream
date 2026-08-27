<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\CashFreeController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\NewStreamController;
use App\Http\Controllers\PhonePeSdkV2Controller;
use App\Http\Controllers\RazorpayController;
use App\Http\Controllers\SubscriptionController as LegacySubscriptionController;
use App\Models\New\Devices;
use App\Models\New\Plan;
use App\Models\New\Episode;
use App\Models\New\Season;
use App\Models\New\Subscription;
use App\Models\MovieModel;
use App\Models\PPVPaymentModel;
use App\Models\New\PaymentHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Kreait\Firebase\Factory;
use App\Services\InvoiceService;

class PaymentController extends Controller
{
    protected $subscriptionController;
    protected $cashfreeController;
    protected $PhonepePaymentController;
    protected $razorpayController;
    protected $streamEventController;
    protected $invoiceService;
    private $firebaseDatabase;

    public function __construct(
        SubscriptionController $subscriptionController,
        CashFreeController $cashFreeController,
        PhonePeSdkV2Controller $phonepePaymentController,
        RazorpayController $razorpayController,
        NewStreamController $streamEventController,
        InvoiceService $invoiceService
    ) {


        $this->subscriptionController = $subscriptionController;
        $this->cashfreeController = $cashFreeController;
        $this->PhonepePaymentController = $phonepePaymentController;
        $this->razorpayController = $razorpayController;
        $this->streamEventController = $streamEventController;
        $this->invoiceService = $invoiceService;
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
                        $endAt = $this->subscriptionEndAtFromPaymentHistory(
                            $payment,
                            $subscription,
                            $startAt,
                            $plan->duration_days
                        );

                        if ($subscription) {
                            $updates = [
                                'plan_id' => $plan->id,
                                'end_at' => $endAt,
                                'is_active' => true,
                            ];

                            if (Subscription::endAtIsExpired($subscription->end_at)) {
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

                        $renewDevice = $this->resolveRenewDevice($uid, $plan->device_type, $deviceId);
                        $renewDeviceId = $renewDevice?->device_token ?? $deviceId;
                        $renewDeviceType = $renewDevice?->device_type ?? $deviceType ?? $plan->device_type;

                        if ($renewDeviceId) {
                            $fakeRequest = new Request([
                                'user_id' => $uid,
                                'device_id' => $renewDeviceId,
                                'subscription_id' => $subscription->id,
                                'device_type' => $renewDeviceType,
                            ]);

                            $this->streamEventController->renew($fakeRequest);
                        }
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
                Log::error('Payment processing failed', [
                    'payment_id' => $payment->id ?? null,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment processing failed',
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
            Log::warning('Razorpay webhook payment record not found; requesting retry', [
                'event' => $event,
                'order_id' => $orderId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Payment record is not ready; retry webhook',
                'order_id' => $orderId,
            ], 503);
        }

        $successful = in_array($event, $successEvents, true);

        try {
            $result = $this->processRazorpayWebhookPayment($payment, $successful);
            $qrResult = $this->updateQrSessionFromRazorpayWebhook(
                $request,
                $successful ? 'payment_completed' : 'failed',
                $orderId
            );

            return response()->json(array_merge([
                'status' => $successful ? 'success' : 'failed',
                'event' => $event,
                'order_id' => $orderId,
            ], $result, $qrResult));
        } catch (\Throwable $e) {
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

    private function processRazorpayWebhookPayment(PaymentHistory $payment, bool $successful): array
    {
        DB::beginTransaction();

        try {
            $payment = PaymentHistory::whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->status === 'success') {
                DB::commit();
                if ($freshPayment = $payment->fresh()) {
                    $this->invoiceService->sendWhatsAppInvoice($freshPayment);
                }

                return [
                    'message' => 'Payment already processed',
                    'already_processed' => true,
                ];
            }

            if (!$successful) {
                $payment->update(['status' => 'failed']);
                DB::commit();

                return [
                    'message' => 'Payment failed and history updated',
                    'already_processed' => false,
                ];
            }

            $uid = (string) $payment->user_id;
            $meta = is_array($payment->meta) ? $payment->meta : [];
            $deviceId = $meta['device_token'] ?? $meta['device_id'] ?? null;
            $deviceType = $meta['device_type'] ?? $payment->device_type ?? null;

            if ($payment->movie_id === null) {
                $plan = Plan::find($payment->plan_id);

                if (!$plan) {
                    throw new \RuntimeException('Invalid plan ID');
                }

                $startAt = now();
                $subscription = Subscription::activeForUserAndDeviceType($uid, $plan->device_type)
                    ->lockForUpdate()
                    ->first();
                $endAt = $this->subscriptionEndAtFromPaymentHistory(
                    $payment,
                    $subscription,
                    $startAt,
                    $plan->duration_days
                );

                if ($subscription) {
                    $updates = [
                        'plan_id' => $plan->id,
                        'end_at' => $endAt,
                        'is_active' => true,
                    ];

                    if (Subscription::endAtIsExpired($subscription->end_at)) {
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
                    'payment_date' => now(),
                    'expiry_date' => $endAt,
                ]);

                $renewDevice = $this->resolveRenewDevice($uid, $plan->device_type, $deviceId);
                $renewDeviceId = $renewDevice?->device_token ?? $deviceId;
                $renewDeviceType = $renewDevice?->device_type ?? $deviceType ?? $plan->device_type;

                if ($renewDeviceId) {
                    $fakeRequest = new Request([
                        'user_id' => $uid,
                        'device_id' => $renewDeviceId,
                        'subscription_id' => $subscription->id,
                        'device_type' => $renewDeviceType,
                    ]);

                    $this->streamEventController->renew($fakeRequest);
                }

                DB::commit();
                if ($freshPayment = $payment->fresh()) {
                    $this->invoiceService->sendWhatsAppInvoice($freshPayment);
                }

                return [
                    'message' => 'Subscription payment processed successfully',
                    'already_processed' => false,
                    'subscription_id' => $subscription->id,
                ];
            }

            $meta['device_token'] = $meta['device_token'] ?? $deviceId;
            $meta['device_type'] = $meta['device_type'] ?? strtolower(trim((string) ($deviceType ?? 'mobile')));

            $payment->update([
                'status' => 'success',
                'payment_date' => now(),
                'expiry_date' => Carbon::now()->addDays(7),
                'meta' => $meta,
            ]);

            DB::commit();
            if ($freshPayment = $payment->fresh()) {
                $this->invoiceService->sendWhatsAppInvoice($freshPayment);
            }

            return [
                'message' => 'PPV payment processed successfully',
                'already_processed' => false,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function resolveRenewDevice(string $userId, string $deviceType, ?string $deviceToken = null): ?Devices
    {
        $query = Devices::where('user_id', $userId)
            ->where('device_type', strtolower(trim($deviceType)));

        if ($deviceToken) {
            $device = (clone $query)
                ->where('device_token', $deviceToken)
                ->first();

            if ($device) {
                return $device;
            }
        }

        return (clone $query)
            ->where('is_owner_device', true)
            ->first();
    }

    private function subscriptionEndAtFromPaymentHistory(
        PaymentHistory $payment,
        ?Subscription $subscription,
        Carbon $startAt,
        int $durationDays
    ): Carbon {
        if ($this->invoiceService->isZoStreamWifiPayment($payment)) {
            return Subscription::endAtForDuration($startAt, 30);
        }

        if ($payment->expiry_date) {
            return Carbon::parse($payment->expiry_date);
        }

        return Subscription::renewEndAt(
            $subscription?->end_at,
            $startAt,
            $durationDays
        );
    }

    private function updateQrSessionFromRazorpayWebhook(Request $request, string $status, string $orderId): array
    {
        try {
            $database = $this->firebaseDatabase();
            $token = $this->razorpayWebhookQrToken($request)
                ?? $this->findQrTokenByOrderId($database, $orderId);

            if (!$token) {
                Log::info('Razorpay webhook QR token not found', [
                    'order_id' => $orderId,
                    'status' => $status,
                ]);

                return [
                    'qr_updated' => false,
                    'qr_message' => 'QR session not found for webhook',
                ];
            }

            $database->getReference('qr_sessions/' . $token)->update([
                'status' => $status,
                'webhook_order_id' => $orderId,
                'webhook_processed_at' => time(),
                'updated_at' => time(),
            ]);

            return [
                'qr_updated' => true,
                'qr_token' => $token,
            ];
        } catch (\Throwable $e) {
            Log::error('Razorpay webhook QR session update failed', [
                'order_id' => $orderId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            return [
                'qr_updated' => false,
                'qr_message' => 'QR session update failed',
            ];
        }
    }

    private function razorpayWebhookQrToken(Request $request): ?string
    {
        $token = $request->input('payload.payment.entity.notes.token')
            ?: $request->input('payload.order.entity.notes.token')
            ?: $request->input('payload.payment.entity.notes.qr_token')
            ?: $request->input('payload.order.entity.notes.qr_token');

        $token = is_string($token) ? trim($token) : '';

        return preg_match('/^[A-Za-z0-9]{22}$/', $token) ? $token : null;
    }

    private function findQrTokenByOrderId($database, string $orderId): ?string
    {
        $sessions = $database
            ->getReference('qr_sessions')
            ->orderByChild('order_id')
            ->equalTo($orderId)
            ->getValue();

        if (!is_array($sessions) || empty($sessions)) {
            return null;
        }

        $token = array_key_first($sessions);

        return is_string($token) && preg_match('/^[A-Za-z0-9]{22}$/', $token) ? $token : null;
    }

    private function firebaseDatabase()
    {
        if ($this->firebaseDatabase) {
            return $this->firebaseDatabase;
        }

        $databaseUrl = config('firebase.database_url');
        $credentials = config('firebase.credentials');

        if (empty($databaseUrl)) {
            throw new \RuntimeException('FIREBASE_DATABASE_URL is missing in .env');
        }

        if (!file_exists($credentials)) {
            throw new \RuntimeException('Firebase credentials file not found: ' . $credentials);
        }

        $firebase = (new Factory)
            ->withServiceAccount($credentials)
            ->withDatabaseUri($databaseUrl);

        $this->firebaseDatabase = $firebase->createDatabase();

        return $this->firebaseDatabase;
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
            'target_device_token' => 'nullable|string|max:255',
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

        $orderId = trim((string) ($razorpayData['order']['id'] ?? ''));
        if ($orderId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Razorpay did not return an order id',
            ], 502);
        }

        $startAt = now();
        $currentSubscription = Subscription::activeForUserAndDeviceType($authUserId, $plan->device_type)
            ->orderByDesc('end_at')
            ->first();
        $expiryDate = Subscription::renewEndAt(
            $currentSubscription?->end_at,
            $startAt,
            $plan->duration_days
        );

        try {
            PaymentHistory::updateOrCreate(
                [
                    'transaction_id' => $orderId,
                    'payment_gateway' => 'razorpay',
                ],
                [
                    'user_id' => $authUserId,
                    'plan_id' => $plan->id,
                    'device_type' => $plan->device_type,
                    'app_payment_type' => 'subscription',
                    'amount' => $amount,
                    'currency' => $currency,
                    'payment_method' => 'checkout',
                    'status' => 'pending',
                    'payment_type' => 'new',
                    'expiry_date' => $expiryDate,
                    'meta' => [
                        'device_token' => $validated['target_device_token'] ?? null,
                        'device_type' => $plan->device_type,
                        'razorpay_order' => $razorpayData['order'],
                    ],
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Failed to persist pending Razorpay subscription order', [
                'order_id' => $orderId,
                'user_id' => $authUserId,
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Payment order could not be saved. Please try again.',
            ], 500);
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

    public function processAppleIapSubscription(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|string|max:225',
            'plan_id' => 'required|integer|exists:n_plans,id',
            'transaction_id' => 'required|string|max:255',
            'product_id' => 'nullable|string|max:255',
            'device_id' => 'nullable|string|max:255',
            'amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
        ]);

        if (!$this->isAppleIapAllowedUser($validated['user_id'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Apple in-app purchase is not enabled for this account',
            ], 403);
        }

        $existingPayment = PaymentHistory::where('transaction_id', $validated['transaction_id'])
            ->where('payment_gateway', 'apple_iap')
            ->where('status', 'success')
            ->first();

        if ($existingPayment) {
            $this->invoiceService->sendWhatsAppInvoice($existingPayment);

            return response()->json([
                'status' => 'success',
                'message' => 'Apple in-app purchase already processed',
                'data' => [
                    'payment_history' => $existingPayment,
                    'subscription' => $existingPayment->subscription,
                ],
            ], 200);
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
            'user_id' => $validated['user_id'],
            'plan_id' => $plan->id,
            'amount' => $validated['amount'] ?? $plan->price,
            'start_at' => now()->toDateString(),
            'currency' => strtoupper($validated['currency'] ?? 'INR'),
            'payment_method' => 'iap',
            'payment_gateway' => 'apple_iap',
            'transaction_id' => $validated['transaction_id'],
            'payment_type' => 'new',
            'status' => 'success',
            'target_device_token' => $validated['device_id'] ?? null,
        ]);

        $subscriptionResponse = $this->subscriptionController
            ->createSubscriptionWithPayment($subscriptionRequest);
        $subscriptionData = json_decode($subscriptionResponse->getContent(), true);

        if (!$subscriptionResponse->isSuccessful() || ($subscriptionData['status'] ?? '') !== 'success') {
            return response()->json([
                'status' => 'error',
                'message' => $subscriptionData['message'] ?? 'Failed to activate Apple in-app purchase',
                'error' => $subscriptionData,
            ], $subscriptionResponse->getStatusCode() >= 400 ? $subscriptionResponse->getStatusCode() : 500);
        }

        $this->sendInvoiceForPaymentPayload($subscriptionData['data']['payment_history'] ?? null);

        return response()->json([
            'status' => 'success',
            'message' => 'Apple in-app purchase activated',
            'data' => $subscriptionData['data'] ?? null,
        ], 200);
    }

    public function processAmazonIapPurchase(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|string|max:225',
            'receipt_id' => 'required|string|max:255',
            'amazon_user_id' => 'required|string|max:255',
            'sku' => 'required|string|max:255',
            'purchase_type' => 'required|string|in:subscription,ppv',
            'plan_id' => 'nullable|required_if:purchase_type,subscription|integer|exists:n_plans,id',
            'content_id' => 'nullable|required_if:purchase_type,ppv|string|max:225',
            'content_type' => 'nullable|required_if:purchase_type,ppv|string|in:movie,episode,season',
            'device_id' => 'required|string|max:255',
        ]);

        $existing = PaymentHistory::where('transaction_id', $validated['receipt_id'])
            ->where('payment_gateway', 'amazon_iap')
            ->where('status', 'success')
            ->first();

        if ($existing) {
            if ((string) $existing->user_id !== (string) $validated['user_id']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This Amazon receipt belongs to another ZoStream account.',
                ], 409);
            }

            $existingMeta = is_array($existing->meta) ? $existing->meta : [];
            if (($existingMeta['amazon_sku'] ?? null) !== $validated['sku']
                || (string) $existing->app_payment_type !== $validated['purchase_type']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This Amazon receipt was already used for another product.',
                ], 409);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Amazon purchase already processed.',
                'data' => [
                    'payment_history' => $existing,
                    'subscription' => $existing->subscription,
                ],
            ]);
        }

        $sharedSecret = trim((string) config('services.amazon_iap.shared_secret', ''));
        if ($sharedSecret === '') {
            Log::error('Amazon IAP shared secret is not configured');

            return response()->json([
                'status' => 'error',
                'message' => 'Amazon purchase verification is not configured.',
            ], 503);
        }

        $sandbox = (bool) config('services.amazon_iap.sandbox', false);
        $baseUrl = 'https://appstore-sdk.amazon.com/' . ($sandbox ? 'sandbox/' : '');
        $verificationUrl = $baseUrl
            . 'version/1.0/verifyReceiptId/developer/' . rawurlencode($sharedSecret)
            . '/user/' . rawurlencode($validated['amazon_user_id'])
            . '/receiptId/' . rawurlencode($validated['receipt_id']);

        try {
            $rvsResponse = Http::acceptJson()->timeout(15)->get($verificationUrl);
        } catch (\Throwable $error) {
            Log::error('Amazon RVS request failed', [
                'receipt_id' => $validated['receipt_id'],
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Amazon purchase verification is temporarily unavailable.',
            ], 503);
        }

        if (!$rvsResponse->successful()) {
            Log::warning('Amazon RVS rejected receipt', [
                'receipt_id' => $validated['receipt_id'],
                'status_code' => $rvsResponse->status(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $rvsResponse->status() === 410
                    ? 'This Amazon purchase was cancelled or expired.'
                    : 'Amazon could not verify this purchase.',
            ], $rvsResponse->status() === 429 ? 503 : 422);
        }

        $receipt = $rvsResponse->json();
        if (!is_array($receipt)
            || (string) ($receipt['receiptId'] ?? '') !== $validated['receipt_id']
            || (string) ($receipt['productId'] ?? '') !== $validated['sku']) {
            return response()->json([
                'status' => 'error',
                'message' => 'Amazon receipt details do not match the requested product.',
            ], 422);
        }

        $cancelDate = isset($receipt['cancelDate']) && is_numeric($receipt['cancelDate'])
            ? Carbon::createFromTimestampMs((int) $receipt['cancelDate'])
            : null;
        if ($cancelDate && $cancelDate->lte(now())) {
            return response()->json([
                'status' => 'error',
                'message' => 'This Amazon purchase is no longer active.',
            ], 422);
        }

        if ($validated['purchase_type'] === 'subscription') {
            return $this->activateAmazonSubscription($validated, $receipt);
        }

        return $this->activateAmazonPpv($validated, $receipt);
    }

    private function activateAmazonSubscription(array $validated, array $receipt)
    {
        if (strtoupper((string) ($receipt['productType'] ?? '')) !== 'SUBSCRIPTION') {
            return response()->json([
                'status' => 'error',
                'message' => 'Amazon product is not a subscription.',
            ], 422);
        }

        $plan = Plan::whereKey($validated['plan_id'])
            ->where('device_type', 'tv')
            ->where('is_active', true)
            ->first();
        $expectedSku = (string) config('services.amazon_iap.subscription_sku_prefix') . $validated['plan_id'];

        if (!$plan || !hash_equals($expectedSku, $validated['sku'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Amazon subscription SKU does not match the selected TV plan.',
            ], 422);
        }

        $renewalDate = isset($receipt['renewalDate']) && is_numeric($receipt['renewalDate'])
            ? Carbon::createFromTimestampMs((int) $receipt['renewalDate'])
            : null;
        $subscriptionRequest = new Request([
            'user_id' => $validated['user_id'],
            'plan_id' => $plan->id,
            'amount' => $plan->price,
            'currency' => 'INR',
            'payment_method' => 'iap',
            'payment_gateway' => 'amazon_iap',
            'transaction_id' => $validated['receipt_id'],
            'payment_type' => 'new',
            'status' => 'success',
            'target_device_token' => $validated['device_id'],
            'end_at' => $renewalDate?->toIso8601String(),
        ]);

        $response = $this->subscriptionController->createSubscriptionWithPayment($subscriptionRequest);
        $payload = json_decode($response->getContent(), true);

        if (!$response->isSuccessful() || ($payload['status'] ?? '') !== 'success') {
            return response()->json([
                'status' => 'error',
                'message' => $payload['message'] ?? 'Failed to activate Amazon subscription.',
            ], $response->getStatusCode() >= 400 ? $response->getStatusCode() : 500);
        }

        $payment = PaymentHistory::where('transaction_id', $validated['receipt_id'])
            ->where('payment_gateway', 'amazon_iap')
            ->first();
        if ($payment) {
            $payment->update(['meta' => array_merge($payment->meta ?? [], [
                'amazon_user_id' => $validated['amazon_user_id'],
                'amazon_sku' => $validated['sku'],
                'amazon_receipt' => $receipt,
            ])]);
            $this->invoiceService->sendWhatsAppInvoice($payment->fresh());
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Amazon subscription activated.',
            'data' => $payload['data'] ?? null,
        ]);
    }

    private function activateAmazonPpv(array $validated, array $receipt)
    {
        if (strtoupper((string) ($receipt['productType'] ?? '')) !== 'CONSUMABLE') {
            return response()->json([
                'status' => 'error',
                'message' => 'Amazon PPV product must be consumable.',
            ], 422);
        }

        $expectedSku = (string) config('services.amazon_iap.ppv_sku_prefix')
            . $validated['content_type'] . '.' . $validated['content_id'];
        if (!hash_equals($expectedSku, $validated['sku'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Amazon PPV SKU does not match the selected content.',
            ], 422);
        }

        $content = match ($validated['content_type']) {
            'movie' => MovieModel::where('id', $validated['content_id'])->first(),
            'episode' => Episode::where('id', $validated['content_id'])->first(),
            'season' => Season::where('id', $validated['content_id'])->first(),
        };
        if (!$content || !(bool) $content->isPayPerView) {
            return response()->json([
                'status' => 'error',
                'message' => 'Selected content is not available as PPV.',
            ], 422);
        }

        $amount = (float) ($validated['content_type'] === 'movie'
            ? ($content->ppv_amount ?? 0)
            : ($content->amount ?? 0));
        $payment = PaymentHistory::create([
            'user_id' => $validated['user_id'],
            'movie_id' => $validated['content_id'],
            'device_type' => 'tv',
            'app_payment_type' => 'ppv',
            'amount' => $amount,
            'currency' => 'INR',
            'payment_method' => 'iap',
            'payment_gateway' => 'amazon_iap',
            'transaction_id' => $validated['receipt_id'],
            'status' => 'success',
            'payment_type' => 'new',
            'payment_date' => now(),
            'expiry_date' => now()->addDays(7),
            'meta' => [
                'device_token' => $validated['device_id'],
                'device_type' => 'tv',
                'content_type' => $validated['content_type'],
                'amazon_user_id' => $validated['amazon_user_id'],
                'amazon_sku' => $validated['sku'],
                'amazon_receipt' => $receipt,
            ],
        ]);

        $this->invoiceService->sendWhatsAppInvoice($payment);

        return response()->json([
            'status' => 'success',
            'message' => 'Amazon PPV rental activated.',
            'data' => [
                'payment_history' => $payment,
                'rental_expires_at' => $payment->expiry_date,
            ],
        ]);
    }

    public function verifyRazorpaySubscriptionPayment(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => 'nullable|integer|exists:n_plans,id',
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
            ->where('payment_gateway', 'razorpay')
            ->first();

        if ($existingPayment) {
            $isPpvPayment = !empty($existingPayment->movie_id);
            if (
                (string) $existingPayment->user_id !== $authUserId ||
                (!$isPpvPayment && (int) $existingPayment->plan_id !== (int) ($validated['plan_id'] ?? 0))
            ) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment order does not match this request',
                ], 403);
            }

            if ($existingPayment->status === 'success') {
                $this->invoiceService->sendWhatsAppInvoice($existingPayment);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment already verified',
                    'data' => [
                        'payment_history' => $existingPayment,
                        'subscription' => $existingPayment->subscription,
                    ],
                ], 200);
            }
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

        if ($existingPayment && !empty($existingPayment->movie_id)) {
            $meta = is_array($existingPayment->meta) ? $existingPayment->meta : [];
            if (!empty($validated['target_device_token'])) {
                $meta['device_token'] = $validated['target_device_token'];
            }
            $existingPayment->update(['meta' => $meta]);

            $result = $this->processRazorpayWebhookPayment($existingPayment, true);
            $completedPayment = $existingPayment->fresh();
            $qrResult = $this->updateQrSessionFromRazorpayWebhook(
                new Request(),
                'payment_completed',
                $validated['razorpay_order_id']
            );

            return response()->json([
                'status' => 'success',
                'message' => $result['message'] ?? 'Payment verified and rental activated',
                'data' => [
                    'payment_history' => $completedPayment,
                    'qr' => $qrResult,
                ],
            ], 200);
        }

        if (empty($validated['plan_id'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'plan_id is required for subscription payments',
            ], 422);
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

        if ($existingPayment) {
            $meta = is_array($existingPayment->meta) ? $existingPayment->meta : [];
            if (!empty($validated['target_device_token'])) {
                $meta['device_token'] = $validated['target_device_token'];
            }
            $meta['device_type'] = $plan->device_type;
            $existingPayment->update(['meta' => $meta]);

            $result = $this->processRazorpayWebhookPayment($existingPayment, true);
            $completedPayment = $existingPayment->fresh('subscription');

            return response()->json([
                'status' => 'success',
                'message' => $result['message'] ?? 'Payment verified and subscription activated',
                'data' => [
                    'payment_history' => $completedPayment,
                    'subscription' => $completedPayment?->subscription,
                ],
            ], 200);
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

        $this->sendInvoiceForPaymentPayload($subscriptionData['data']['payment_history'] ?? null);

        return response()->json([
            'status' => 'success',
            'message' => 'Payment verified and subscription activated',
            'data' => $subscriptionData['data'] ?? null,
        ], 200);
    }

    private function sendInvoiceForPaymentPayload($paymentPayload): void
    {
        $paymentId = is_array($paymentPayload)
            ? ($paymentPayload['id'] ?? null)
            : (is_object($paymentPayload) ? ($paymentPayload->id ?? null) : null);

        if (!$paymentId) {
            return;
        }

        $payment = PaymentHistory::find($paymentId);

        if ($payment) {
            $this->invoiceService->sendWhatsAppInvoice($payment);
        }
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

    private function isAppleIapAllowedUser(string $userId): bool
    {
        $allowed = array_filter(array_map(
            static fn ($value) => trim((string) $value),
            explode(',', (string) env('APPLE_IAP_USER_IDS', 'AW7ovVnTdgWuvE1Uke7QTQ5OEQt1'))
        ));

        return in_array(trim($userId), $allowed, true);
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
