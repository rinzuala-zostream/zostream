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

    private function checkPaymentStatus($phonepeReq, $merchantOrderId)
    {
        $phonepeResponse = $this->PhonepePaymentController->getOrderStatus($phonepeReq, $merchantOrderId);

        // Decode JSON into array
        $paymentResponse = json_decode($phonepeResponse->getContent(), true);

        return $paymentResponse; // ✅ return as array
    }

}
