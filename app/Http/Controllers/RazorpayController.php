<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class RazorpayController extends Controller
{
    private string $env;
    private string $keyId;
    private string $keySecret;
    private string $baseUrl;

    public function __construct()
    {
        $this->env = strtoupper(config('razorpay.env', 'SANDBOX'));
        $this->baseUrl = rtrim(config('razorpay.base_url'), '/');

        if ($this->env === 'PRODUCTION') {
            $this->keyId = (string) config('razorpay.live.key_id');
            $this->keySecret = (string) config('razorpay.live.key_secret');
        } else {
            $this->keyId = (string) config('razorpay.test.key_id');
            $this->keySecret = (string) config('razorpay.test.key_secret');
        }
    }

    /**
     * Create a Razorpay order
     */
    public function createOrder(Request $request)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
            'currency' => 'sometimes|string|size:3',
            'receipt' => 'sometimes|string|max:64',
            'capture' => 'sometimes|boolean',
            'notes' => 'sometimes|array',
        ]);

        $payload = [
            'amount' => $this->toPaise($data['amount']),
            'currency' => strtoupper($data['currency'] ?? 'INR'),
        ];

        if (!empty($data['receipt'])) {
            $payload['receipt'] = $data['receipt'];
        }

        if (array_key_exists('capture', $data)) {
            $payload['payment_capture'] = $data['capture'] ? 1 : 0;
        }

        if (!empty($data['notes'])) {
            $payload['notes'] = $data['notes'];
        }

        $client = $this->makeClient();

        try {
            $res = $client->post('orders', ['json' => $payload]);
            $status = $res->getStatusCode();
            $body = json_decode((string) $res->getBody(), true);

            if ($status < 200 || $status >= 300) {
                return response()->json([
                    'ok' => false,
                    'message' => $body['error']['description'] ?? 'Order creation failed',
                    'body' => $body,
                ], 400);
            }

            return response()->json([
                'ok' => true,
                'env' => $this->env,
                'order' => $body,
                
            ], 201);

        } catch (RequestException $e) {
            Log::error('Razorpay createOrder exception', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'API error'], 500);
        }
    }

    /**
     * Check Payment Status for a given orderId
     * GET /v1/orders/{orderId}/payments
     */
    public function checkPaymentStatus(string $orderId)
    {
        $client = $this->makeClient();

        try {
            // Fetch order info
            $orderRes = $client->get("orders/{$orderId}");
            $order = json_decode((string) $orderRes->getBody(), true);

            // Fetch payments under this order
            $payRes = $client->get("orders/{$orderId}/payments");
            $payBody = json_decode((string) $payRes->getBody(), true);
            $payments = $payBody['items'] ?? [];

            // Determine status
            $isPaid = false;
            $paymentStatus = null;

            foreach ($payments as $p) {
                if (($p['status'] ?? '') === 'captured') {
                    $isPaid = true;
                    $paymentStatus = 'captured';
                    break;
                }
            }

            $orderStatus = strtoupper($order['status'] ?? 'UNKNOWN'); // created, paid, attempted, etc.

            return response()->json([
                'success' => $isPaid,
                'env' => $this->env,
                'code' => $isPaid ? 'PAYMENT_SUCCESS' : $orderStatus,
                'data' => [
                    'state' => $isPaid
                        ? 'COMPLETED'
                        : ($orderStatus === 'CREATED' || $orderStatus === 'ATTEMPTED'
                            ? 'PENDING'
                            : $orderStatus),
                    'order' => $order,
                    'payments' => $payments,
                ],
            ], 200);

        } catch (RequestException $e) {
            Log::error('Razorpay checkPaymentStatus exception', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'env' => $this->env,
                'code' => 'ERROR',
                'data' => [
                    'state' => 'ERROR',
                    'order' => null,
                    'payments' => [],
                ],
            ], 500);
        }
    }

    private function toPaise(float $rupees): int
    {
        return (int) round($rupees * 100);
    }

    private function makeClient(): Client
    {
        return new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout' => 20,
            'auth' => [$this->keyId, $this->keySecret],
            'http_errors' => false,
        ]);
    }
}
