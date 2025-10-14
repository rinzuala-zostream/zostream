<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class RazorpayController extends Controller
{
    private string $env = 'PRODUCTION';
    private string $keyId = '';
    private string $keySecret = '';
    private string $baseUrl;

    public function __construct()
    {
        // Same base URL for test & live; credentials decide the environment.
        $this->baseUrl = rtrim((string) config('razorpay.base_url', 'https://api.razorpay.com/v1'), '/');
    }

    /**
     * One-time boot per request: decide env, load creds.
     */
    private function boot(Request $request): void
    {
        $this->env = $this->resolveEnv($request);              // SANDBOX | PRODUCTION
        [$this->keyId, $this->keySecret] = $this->creds($this->env);
    }

    /**
     * Create a Razorpay order
     */
    public function createOrder(Request $request)
    {
        $this->boot($request);

        $data = $request->validate([
            'amount'   => 'required|numeric|min:1',   // rupees
            'currency' => 'sometimes|string|size:3',  // default INR
            'receipt'  => 'sometimes|string|max:64',
            'capture'  => 'sometimes|boolean',        // maps to payment_capture
            'notes'    => 'sometimes|array',
        ]);

        $payload = [
            'amount'   => $this->toPaise((float) $data['amount']),
            'currency' => strtoupper($data['currency'] ?? 'INR'),
        ];

        if (!empty($data['receipt'])) {
            $payload['receipt'] = $data['receipt'];
        }

        if (array_key_exists('capture', $data)) {
            // Razorpay expects 1 or 0
            $payload['payment_capture'] = $data['capture'] ? 1 : 0;
        }

        if (!empty($data['notes'])) {
            $payload['notes'] = $data['notes'];
        }

        $client = $this->makeClient();

        try {
            $res   = $client->post('orders', ['json' => $payload]);
            $code  = $res->getStatusCode();
            $body  = json_decode((string) $res->getBody(), true);

            if ($code < 200 || $code >= 300) {
                // Razorpay error payloads usually include error.description
                $msg = $body['error']['description'] ?? 'Order creation failed';
                return response()->json([
                    'ok'      => false,
                    'env'     => $this->env,
                    'message' => $msg,
                    'body'    => $body,
                ], 400);
            }

            return response()->json([
                'ok'    => true,
                'env'   => $this->env,
                'order' => $body,
            ], 201);

        } catch (RequestException $e) {
            Log::error('Razorpay createOrder exception', [
                'error' => $e->getMessage(),
                'env'   => $this->env,
            ]);

            $resp = $e->getResponse();
            if ($resp) {
                $body = json_decode((string) $resp->getBody(), true);
                return response()->json([
                    'ok'      => false,
                    'env'     => $this->env,
                    'message' => $body['error']['description'] ?? 'API error',
                    'body'    => $body,
                ], 400);
            }

            return response()->json(['ok' => false, 'env' => $this->env, 'message' => 'API error'], 500);
        }
    }

    /**
     * Check Payment Status for a given orderId
     * GET /v1/orders/{orderId}/payments
     */
    public function checkPaymentStatus(Request $request, string $orderId)
    {
        $this->boot($request);

        $client = $this->makeClient();

        try {
            // Fetch order
            $orderRes = $client->get("orders/{$orderId}");
            $order    = json_decode((string) $orderRes->getBody(), true);

            // If order lookup failed, surface error
            if ($orderRes->getStatusCode() >= 400) {
                $msg = $order['error']['description'] ?? 'Order fetch failed';
                return response()->json([
                    'success' => false,
                    'env'     => $this->env,
                    'code'    => 'ERROR',
                    'data'    => ['state' => 'ERROR', 'order' => $order, 'payments' => []],
                    'message' => $msg,
                ], 400);
            }

            // Fetch payments under this order
            $payRes   = $client->get("orders/{$orderId}/payments");
            $payBody  = json_decode((string) $payRes->getBody(), true);
            $payments = $payBody['items'] ?? [];

            // Determine status: treat any "captured" payment as success
            $isPaid        = false;
            $paymentStatus = null;

            foreach ($payments as $p) {
                $status = strtolower($p['status'] ?? '');
                if ($status === 'captured') {
                    $isPaid = true;
                    $paymentStatus = 'captured';
                    break;
                }
            }

            $orderStatus = strtoupper((string) ($order['status'] ?? 'UNKNOWN')); // CREATED | PAID | ATTEMPTED ...

            return response()->json([
                'success' => $isPaid,
                'env'     => $this->env,
                'code'    => $isPaid ? 'PAYMENT_SUCCESS' : $orderStatus,
                'data'    => [
                    'state'    => $isPaid
                        ? 'COMPLETED'
                        : (in_array($orderStatus, ['CREATED', 'ATTEMPTED'], true) ? 'PENDING' : $orderStatus),
                    'order'    => $order,
                    'payments' => $payments,
                    'reason'   => $paymentStatus ?? null,
                ],
            ], 200);

        } catch (RequestException $e) {
            Log::error('Razorpay checkPaymentStatus exception', [
                'error' => $e->getMessage(),
                'env'   => $this->env,
                'order' => $orderId,
            ]);

            $resp = $e->getResponse();
            if ($resp) {
                $body = json_decode((string) $resp->getBody(), true);
                return response()->json([
                    'success' => false,
                    'env'     => $this->env,
                    'code'    => 'ERROR',
                    'data'    => [
                        'state'    => 'ERROR',
                        'order'    => $body ?? null,
                        'payments' => [],
                    ],
                    'message' => $body['error']['description'] ?? 'API error',
                ], 400);
            }

            return response()->json([
                'success' => false,
                'env'     => $this->env,
                'code'    => 'ERROR',
                'data'    => ['state' => 'ERROR', 'order' => null, 'payments' => []],
            ], 500);
        }
    }

    // ===== Helpers =====

    private function creds(string $env): array
    {
        if ($env === 'PRODUCTION') {
            $id  = (string) config('razorpay.live.key_id', '');
            $sec = (string) config('razorpay.live.key_secret', '');
        } else {
            $id  = (string) config('razorpay.test.key_id', '');
            $sec = (string) config('razorpay.test.key_secret', '');
        }

        // Fallback to live if not configured properly (optional)
        if ($id === '' || $sec === '') {
            $id  = (string) config('razorpay.live.key_id', $id);
            $sec = (string) config('razorpay.live.key_secret', $sec);
        }

        return [$id, $sec];
    }

    private function resolveEnv(Request $request): string
    {
        $h   = strtoupper(trim((string) $request->header('X-RZ-Env', '')));
        $p   = strtoupper(trim((string) $request->input('env', '')));
        $raw = $h ?: $p;

        return in_array($raw, ['PRODUCTION', 'SANDBOX'], true) ? $raw : 'PRODUCTION';
    }

    private function toPaise(float $rupees): int
    {
        return (int) round($rupees * 100);
    }

    private function makeClient(): Client
    {
        return new Client([
            'base_uri'    => $this->baseUrl . '/',
            'timeout'     => 20,
            'auth'        => [$this->keyId, $this->keySecret],
            'http_errors' => false, // let us parse JSON even on 4xx
        ]);
    }
}
