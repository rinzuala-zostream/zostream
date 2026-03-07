<?php

namespace App\Http\Controllers;

use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class PPVPriceCalculate extends Controller
{
    private $validApiKey;
    protected $subscriptionController;

    public function __construct(SubscriptionController $subscriptionController)
    {
        $this->validApiKey = config('app.api_key');
        $this->subscriptionController = $subscriptionController;
    }

    public function getPPVPrice(Request $request)
    {
        try {
            // ✅ Check API Key
            $apiKey = $request->header('X-Api-Key');
            if ($apiKey !== $this->validApiKey) {
                return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
            }

            // ✅ Validate input
            $request->validate([
                'user_id' => 'required|string',
                'amount' => 'required|numeric|min:0.01',
                'device_type' => 'nullable|string'
            ]);

            $userId = $request->query('user_id');
            $ppvAmount = (float) $request->query('amount');
            $device_type = $request->query('device_type');
            $discount = 0;
            $discountPercent = 0;
            $finalPPVPrice = $ppvAmount;
            $currentDate = new DateTime();

            // ✅ Fetch subscription data
            try {
                $subscriptionRequest = new Request(['id' => $userId, 'device_type' => $device_type]);
                $subscriptionRequest->headers->set('X-Api-Key', $apiKey);

                $response = $this->subscriptionController->getSubscription($subscriptionRequest);
                $subscriptionData = is_object($response) && method_exists($response, 'getData')
                    ? $response->getData(true)
                    : (is_array($response) ? $response : []);

            } catch (Exception $ex) {
                Log::error("PPV: Subscription fetch error - " . $ex->getMessage());
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to fetch subscription',
                    'error' => $ex->getMessage(),
                ], 500);
            }

            // ✅ Ensure data valid
            if (
                is_array($subscriptionData)
                && isset($subscriptionData['create_date'], $subscriptionData['period'])
            ) {
                try {
                    $createDate = new DateTime($subscriptionData['create_date']);
                    $endDate = (clone $createDate)->modify("+{$subscriptionData['period']} days");

                    $subscriptionActive = $currentDate >= $createDate && $currentDate <= $endDate;

                    if ($subscriptionActive) {
                        $interval = $createDate->diff($endDate);
                        $months = ($interval->y * 12) + $interval->m;

                        if ($months < 1) {
                            $discountPercent = 2;
                        } elseif ($months === 1 || $months === 4) {
                            $discountPercent = 5;
                        } elseif ($months === 6) {
                            $discountPercent = 7;
                        } else {
                            $discountPercent = 10;
                        }

                        $discount = ($discountPercent / 100) * $ppvAmount;
                        $finalPPVPrice = $ppvAmount - $discount;
                    }
                } catch (Exception $ex) {
                    Log::warning("PPV: Invalid date format - " . $ex->getMessage());
                }
            } else {
                Log::info('PPV: Missing subscription fields', ['data' => $subscriptionData]);
            }

            // ✅ Return response
            return response()->json([
                'status' => 'ok',
                'ppv_amount' => round($ppvAmount, 2),
                'discount' => round($discount, 2),
                'discount_percent' => round($discountPercent, 2),
                'final_ppv_price' => round($finalPPVPrice, 2),
                'created_date' => $currentDate->format('F j, Y'),
                'end_date' => (clone $currentDate)->modify('+7 days')->format('F j, Y'),
            ]);
        } catch (Exception $e) {
            Log::error('Error calculating PPV price: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
