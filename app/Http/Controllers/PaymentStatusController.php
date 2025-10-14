<?php

namespace App\Http\Controllers;

use App\Models\PPVPaymentModel;
use App\Models\TempPaymentModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class PaymentStatusController extends Controller
{
    private $merchantId = 'M221AEW7ARW15';
    private $saltKey = '1d8c7b88-710d-4c48-a70a-cdd08c8cabac';

    private $validApiKey;
    protected $subscriptionController;
    protected $cashfreeController;
    protected $PhonepePaymentController;
    protected $razorpayController;
    
    public function __construct(
        SubscriptionController $subscriptionController,
        CashFreeController $cashFreeController,
        PhonePeSdkV2Controller $phonepePaymentController,
        RazorpayController $razorpayController
    ) {
        $this->validApiKey = config('app.api_key');
        $this->subscriptionController = $subscriptionController;
        $this->cashfreeController = $cashFreeController;
        $this->PhonepePaymentController = $phonepePaymentController;
        $this->razorpayController = $razorpayController;
    }

    public function processUserPayments(Request $request)
    {
        $request->validate([
            'user_id' => 'required|string',
        ]);

        $uid = $request->query('user_id');

        $tempDataList = TempPaymentModel::where('user_id', $uid)->get();
        if ($tempDataList->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => 'No pending payments found']);
        }

        $successCount = 0;
        $failureCount = 0;

        try {
            foreach ($tempDataList as $tempData) {
                $merchantOrderId = $tempData->transaction_id;

                // Step 1: Get payment status
                if ($tempData->pg === 'phonepe') {
                     $h = strtolower(trim((string) $request->header('X-PP-Env', 'production')));
                     $phonepeReq = new Request(['X-PP-Env' => $h]);
                    $paymentResponse = $this->checkPaymentStatus($phonepeReq, $merchantOrderId);
                } else if ($tempData->pg === 'razorpay') {
                    $orderId = $tempData->transaction_id;
                    $h = strtolower(trim((string) $request->header('X-RZ-Env', 'production')));
                    $razorpayReq = new Request(['X-RZ-Env' => $h]);
                    $razorResponse = $this->razorpayController->checkPaymentStatus($razorpayReq, $orderId);
                    $paymentResponse = json_decode($razorResponse->getContent(), true);
                } else {
                    $cashfreeReq = new Request(['order_id' => $merchantOrderId]);
                    $cashfreeResponse = $this->cashfreeController->checkPayment($cashfreeReq);
                    $paymentResponse = json_decode($cashfreeResponse->getContent(), true);
                }

                $paymentSuccess = isset($paymentResponse['success']) && $paymentResponse['success'] === true;
                $paymentCompleted = isset($paymentResponse['code']) && $paymentResponse['code'] === 'PAYMENT_SUCCESS' ||
                    isset($paymentResponse['data']['state']) && $paymentResponse['data']['state'] === 'COMPLETED';

                if ($paymentSuccess && $paymentCompleted) {
                    // Subscription
                    if ($tempData->payment_type === 'Subscription') {

                        $currentDate = Carbon::parse($tempData->created_at);


                        $fakeRequest = new Request([
                            'id' => $tempData->user_id,
                            'period' => $tempData->subscription_period,
                            'plan' => $tempData->plan,
                            'currentDate' => $currentDate->toDateTimeString(),
                            'device_type' => $tempData->device_type
                        ]);
                        $fakeRequest->headers->set('X-Api-Key', $this->validApiKey);

                        $response = $this->subscriptionController->addSubscription($fakeRequest);
                        $responseData = $response->getData(true);

                        if ($responseData['status'] === 'success') {

                            $planEnd = $currentDate->copy()->addDays($tempData->period);

                            $historyRequest = new Request([
                                'uid' => $tempData->user_id,
                                'pid' => $tempData->transaction_id,
                                'plan' => $tempData->plan,
                                'pg' => $tempData->pg,
                                'total_pay' => $tempData->total_pay,
                                'amount' => $tempData->total_pay,
                                'plan_start' => $currentDate->toDateTimeString(),
                                'plan_end' => $planEnd->toDateTimeString(),
                                'mail' => $tempData->user_mail ?? '',
                                'platform' => $tempData->device_type ?? '',
                                'hming' => $tempData->hming ?? '',
                            ]);
                            $this->subscriptionController->addHistory($historyRequest);

                            // Send success email
                            $amount = $tempData->amount ?? '0.00';
                            $paymentDate = $currentDate->format('F j, Y h:i:s');
                            $paymentMethod = $tempData->pg ?? 'Zo Stream Balance';
                            $uniqueTxnId = $tempData->transaction_id;
                            $type = $tempData->payment_type;
                            $platform = $tempData->device_type;
                            $plan = $tempData->plan;

                            Http::asForm()->post('https://zostream.in/mail/success_payment.php', [
                                'recipient' => $tempData->user_mail,
                                'subject' => 'Payment Confirmation from Zo Stream',
                                'amount' => $amount,
                                'date' => $paymentDate,
                                'method' => $paymentMethod,
                                'type' => $type,
                                'platform' => $platform,
                                'transaction_id' => $uniqueTxnId,
                                'plan' => $plan,
                            ]);

                            TempPaymentModel::where('transaction_id', $merchantOrderId)->delete();
                            $successCount++;
                        } else {
                            $failureCount++;
                        }
                    }

                    // PPV
                    elseif ($tempData->payment_type === 'PPV') {

                        $data = [
                            'payment_id' => $merchantOrderId,
                            'user_id' => $tempData->user_id,
                            'movie_id' => $tempData->content_id,
                            'rental_period' => $tempData->subscription_period,
                            'purchase_date' => $tempData->created_at,
                            'amount_paid' => $tempData->total_pay,
                            'platform' => $tempData->device_type,
                            'pg' => $tempData->pg,
                            'payment_status' => 'completed',
                            'created_at' => $tempData->created_at,
                            'updated_at' => $tempData->created_at,
                        ];

                        PPVPaymentModel::insert($data);
                        TempPaymentModel::where('transaction_id', $merchantOrderId)->delete();
                        $successCount++;
                    }
                } else {
                    // Payment failed or status invalid
                    TempPaymentModel::where('transaction_id', $merchantOrderId)->delete();
                    $failureCount++;
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => "Processed payments. Success: $successCount, Failures: $failureCount",
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function checkPaymentStatus($phonepeReq, $merchantOrderId)
    {
        $phonepeResponse = $this->PhonepePaymentController->getOrderStatus($phonepeReq, $merchantOrderId);

        // Decode JSON into array
        $paymentResponse = json_decode($phonepeResponse->getContent(), true);

        return $paymentResponse; // ✅ return as array
    }

}
