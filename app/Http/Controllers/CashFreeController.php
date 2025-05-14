<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;

class CashFreeController extends Controller
{
    private $clientId;
    private $clientSecret;
    private $apiVersion;
    private $baseUrl;

    public function __construct()
    {
        $this->clientId = config('cashfree.client_id');   // store these in config
        $this->clientSecret = config('cashfree.client_secret');
        $this->apiVersion = '2022-01-01';
        $this->baseUrl = 'https://api.cashfree.com/pg/orders'; // change to production when ready
    }

    public function createOrder(Request $request)
    {
        $request->validate([
            'customer_details.customer_id' => 'required|string',
            'customer_details.customer_email' => 'required|email',
            'customer_details.customer_phone' => 'required|string',
            'order_meta.notify_url' => 'nullable|url',
            'order_meta.return_url' => 'nullable|url',
            'order_amount' => 'required|numeric|min:0',
            'order_currency' => 'required|string|max:3',
        ]);

        $client = new Client();

        try {
            $response = $client->post($this->baseUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'x-api-version' => $this->apiVersion,
                    'x-client-id' => $this->clientId,
                    'x-client-secret' => $this->clientSecret,
                ],
                'json' => $request->all()
            ]);

            $responseData = json_decode($response->getBody(), true);

            return response()->json([
                'status' => 'success',
                'data' => $responseData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function checkPayment(Request $request)
    {
        $request->validate([
            'order_id' => 'required|string',
        ]);

        $client = new Client();
        $orderId = $request->query('order_id');

        try {
            $response = $client->get($this->baseUrl . "/{$orderId}/payments", [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'x-api-version' => $this->apiVersion,
                    'x-client-id' => $this->clientId,
                    'x-client-secret' => $this->clientSecret,
                ],
            ]);

            $responseData = json_decode($response->getBody(), true);

            // Default success and code
            $success = false;
            $code = 'UNKNOWN';
            $data = $responseData;

            // If Cashfree returns a list of payments
            if (isset($responseData[0])) {
                $payment = $responseData[0];

                if (isset($payment['payment_status']) && $payment['payment_status'] === 'SUCCESS') {
                    $success = true;
                    $code = 'PAYMENT_SUCCESS';
                    $data = [
                        'state' => 'COMPLETED', // you wanted 'state' for your condition
                        'payment' => $payment
                    ];
                } else {
                    $code = $payment['payment_status'] ?? 'FAILED';
                    $data = [
                        'state' => 'FAILED',
                        'payment' => $payment
                    ];
                }
            }

            return response()->json([
                'success' => $success,
                'code' => $code,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'code' => 'EXCEPTION',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
