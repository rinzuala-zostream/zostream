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
        $this->baseUrl = 'https://sandbox.cashfree.com/pg/orders'; // change to production when ready
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
}
