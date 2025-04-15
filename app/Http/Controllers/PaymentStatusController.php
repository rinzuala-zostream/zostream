<?php

namespace App\Http\Controllers;

use App\Models\PPVPaymentModel;
use App\Models\TempPaymentModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
class PaymentStatusController extends Controller
{
    private $merchantId = 'M221AEW7ARW15';
    private $saltKey = '1d8c7b88-710d-4c48-a70a-cdd08c8cabac';

    private $validApiKey;
    protected $subscriptionController;

    public function __construct(SubscriptionController $subscriptionController)
    {
        $this->validApiKey = config('app.api_key');
        $this->subscriptionController = $subscriptionController;
    }

    public function processUserPayments(Request $request)
    {

        $request->validate([
            'user_id' => 'required|string',
        ]);
    
        $uid = $request->user_id; // Corrected here
    
        if (!$uid) {
            return response()->json(['status' => 'error', 'message' => 'User ID is required']);
        }

        $tempDataList = TempPaymentModel::where('user_id', $uid)->get();
        if ($tempDataList->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => 'No pending payments found']);
        }

        $successCount = 0;
        $failureCount = 0;

        try {
            foreach ($tempDataList as $tempData) {
                $transactionId = $tempData->transaction_id;
                $paymentResponse = $this->checkPaymentStatus($transactionId);

                if ($paymentResponse['success'] === true &&
                    ($paymentResponse['code'] === 'PAYMENT_SUCCESS' || $paymentResponse['data']['state'] === 'COMPLETED')
                ) {
                    if ($tempData->payment_type === 'Subscription') {

                        $fakeRequest = new Request([
                            'id' => $tempData->user_id,
                            'period' => $tempData->subscription_period,
                            'currentDate' => $tempData->created_at->toDateTimeString()
                        ]);                        

                        $fakeRequest->headers->set('api_key', $this->validApiKey);
                        
                        $response = $this->subscriptionController->addSubscription($fakeRequest);
                        $responseData = $response->getData(true);
                        
                        if ($responseData['status'] === 'success') {
                            TempPaymentModel::where('transaction_id', $transactionId)->delete();
                            $successCount++;
                        } else {
                            $failureCount++;
                        }
                        
                    } elseif ($tempData->payment_type === 'PPV') {
                        $existing = PPVPaymentModel::where('user_id', $tempData->user_id)
                            ->where('movie_id', $tempData->content_id)
                            ->first();

                        $data = [
                            'payment_id'     => $tempData->transaction_id,
                            'user_id'        => $tempData->user_id,
                            'movie_id'       => $tempData->content_id,
                            'rental_period'  => $tempData->subscription_period,
                            'purchase_date'  => $tempData->created_at,
                            'amount_paid'    => $tempData->amount,
                            'payment_status' => 'completed',
                            'created_at'     => $tempData->created_at,
                            'updated_at'     => $tempData->created_at,
                        ];

                        if ($existing) {
                            PPVPaymentModel::where('user_id', $tempData->user_id)
                                ->where('movie_id', $tempData->content_id)
                                ->update($data);
                        } else {
                            PPVPaymentModel::insert($data);
                        }

                        TempPaymentModel::where('transaction_id', $transactionId)->delete();
                        $successCount++;
                    }
                } else {
                    TempPaymentModel::where('transaction_id', $transactionId)->delete();
                    $failureCount++;
                }
            }

            return response()->json([
                'status'  => 'success',
                'message' => "Processed payments. Success: $successCount, Failures: $failureCount",
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function checkPaymentStatus($transactionId)
    {
        $path = "/pg/v1/status/{$this->merchantId}/{$transactionId}";
        $checksum = hash('sha256', $path . $this->saltKey) . "###1";
        $url = "https://api.phonepe.com/apis/hermes{$path}";

        $response = Http::withHeaders([
            'Content-Type'   => 'application/json',
            'X-VERIFY'       => $checksum,
            'X-MERCHANT-ID'  => $this->merchantId
        ])->get($url);

        return $response->json();
    }
}
