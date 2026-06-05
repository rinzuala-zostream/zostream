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

                        $subscription = Subscription::create([
                            'user_id' => $uid,
                            'plan_id' => $plan->id,
                            'start_at' => $startAt,
                            'end_at' => $startAt->copy()->addDays($plan->duration_days),
                            'is_active' => true,
                        ]);

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

                        $payment->update([
                            'status' => 'success',
                            'payment_date' => now(),
                            'expiry_date' => Carbon::now()->addDays(7) // 7-day access for PPV
                        ]);
                    }

                    $successCount++;

                    DB::commit();
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

        $status = $successCount > 0 ? 'success' : 'error';

        return response()->json([
            'status' => $status,
            'message' => "Processed payments. Success: $successCount, Failures: $failureCount",
        ], 200);
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
        $receipt = substr('sub_' . $plan->id . '_' . $authUserId . '_' . now()->timestamp, 0, 64);
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
            strtolower(trim((string) $request->header('X-RZ-Env', 'production')))
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
            strtolower(trim((string) $request->header('X-RZ-Env', 'production')))
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
            'currency' => strtoupper($validated['currency'] ?? 'INR'),
            'payment_method' => 'checkout',
            'payment_gateway' => 'razorpay',
            'transaction_id' => $validated['razorpay_order_id'],
            'payment_type' => 'new',
            'status' => 'success',
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

        return in_array($raw, ['PRODUCTION', 'SANDBOX'], true) ? $raw : 'PRODUCTION';
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
