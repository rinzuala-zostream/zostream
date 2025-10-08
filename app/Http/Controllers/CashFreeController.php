<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class CashFreeController extends Controller
{
    /** @var string */
    private string $apiVersion = '2025-01-01'; // Cashfree PG API version

    /**
     * Create Order (per-request environment)
     * POST /api/cashfree/orders
     *
     * Body must include:
     * - order_amount (numeric)
     * - order_currency (e.g., INR)
     * - customer_details{customer_id, customer_email, customer_phone}
     * - order_meta.return_url (optional)
     *
     * Optional env override:
     * - Header: X-CF-Env: PRODUCTION | SANDBOX
     * - Param:  env=PRODUCTION|SANDBOX  (query or JSON body)
     * Defaults to PRODUCTION if not provided/empty.
     */
    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'customer_details.customer_id' => 'required|string',
            'customer_details.customer_email' => 'required|email',
            'customer_details.customer_phone' => 'required|string',
            'order_meta.return_url' => 'nullable|url',
            // 'order_meta.notify_url' is NOT supported on this API version
            'order_amount' => 'required|numeric|min:0.0',
            'order_currency' => 'required|string|size:3',
            'order_note' => 'nullable|string',
            // env can be present but is optional; if present should be PRODUCTION|SANDBOX
            'env' => 'nullable|string|in:PRODUCTION,SANDBOX,production,sandbox',
        ]);

        $env = $this->resolveEnv($request); // PRODUCTION | SANDBOX
        [$clientId, $clientSecret] = $this->creds($env);
        $baseUrl = $this->baseUrl($env);

        $client = new Client([
            'base_uri' => $baseUrl,
            'timeout' => 15,
        ]);

        try {
            $resp = $client->post('/pg/orders', [
                'headers' => $this->headers($clientId, $clientSecret),
                'json' => $validated,
            ]);

            $data = json_decode($resp->getBody()->getContents(), true);

            // Expect: order_id + payment_session_id (use this in Android SDK)
            // Docs: Create Order returns payment_session_id for initiating payments. :contentReference[oaicite:1]{index=1}
            return response()->json([
                'status' => 'success',
                'env' => $env,
                'data' => Arr::only($data, ['order_id', 'payment_session_id']) + ['raw' => $data],
                
            ]);
        } catch (RequestException $e) {
            $body = $e->getResponse()?->getBody()?->getContents();
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'body' => $this->safeJson($body),
            ], $e->getResponse()?->getStatusCode() ?: 500);
        }
    }

    /**
     * Verify payment by order_id (per-request environment)
     * GET /api/cashfree/order-status?order_id=...
     * Optional: env=PRODUCTION|SANDBOX (header or query); defaults to PRODUCTION.
     */
    public function checkPayment(Request $request)
    {
        $request->validate([
            'order_id' => 'required|string',
            'env'      => 'nullable|string|in:PRODUCTION,SANDBOX,production,sandbox',
        ]);

        $orderId = $request->query('order_id');
        $env = $this->resolveEnv($request);
        [$clientId, $clientSecret] = $this->creds($env);
        $baseUrl = $this->baseUrl($env);

        $client = new Client([
            'base_uri' => $baseUrl,
            'timeout'  => 15,
        ]);

        try {
            // 1) Verify order status
            $respOrder = $client->get("/pg/orders/{$orderId}", [
                'headers' => $this->headers($clientId, $clientSecret),
            ]);
            $order = json_decode($respOrder->getBody()->getContents(), true) ?: [];

            $orderStatus = strtoupper($order['order_status'] ?? 'UNKNOWN');
            $isPaid = ($orderStatus === 'PAID');

            // 2) Optional: payments list
            $payments = [];
            try {
                $respPayments = $client->get("/pg/orders/{$orderId}/payments", [
                    'headers' => $this->headers($clientId, $clientSecret),
                ]);
                $payments = json_decode($respPayments->getBody()->getContents(), true) ?: [];

                if (is_array($payments)) {
                    foreach ($payments as $p) {
                        $ps = strtoupper($p['payment_status'] ?? '');
                        if (in_array($ps, ['SUCCESS', 'CAPTURED', 'COMPLETED'], true)) {
                            $isPaid = true;
                            break;
                        }
                    }
                }
            } catch (\Throwable $ignored) {
                // continue with order status alone
            }

            return response()->json([
                'success' => $isPaid,
                'env'     => $env,
                'code'    => $isPaid ? 'PAYMENT_SUCCESS' : $orderStatus,
                'data'    => [
                    'state'     => $isPaid ? 'COMPLETED' : ($orderStatus === 'ACTIVE' ? 'PENDING' : $orderStatus),
                    'order'     => $order,
                    'payments'  => $payments,
                ],
            ]);
        } catch (RequestException $e) {
            $body = $e->getResponse()?->getBody()?->getContents();
            return response()->json([
                'success' => false,
                'env'     => $env,
                'code'    => 'EXCEPTION',
                'message' => $e->getMessage(),
                'body'    => $this->safeJson($body),
            ], $e->getResponse()?->getStatusCode() ?: 500);
        }
    }

    /**
     * Resolve environment from request:
     * - Header X-CF-Env or param env
     * - Accepts PRODUCTION|SANDBOX (case-insensitive)
     * - Default: PRODUCTION
     */
    private function resolveEnv(Request $request): string
    {
        $h = (string) $request->header('X-CF-Env', '');
        $p = (string) ($request->input('env', '') ?? '');
        $raw = strtoupper(trim($h ?: $p));

        return in_array($raw, ['PRODUCTION', 'SANDBOX'], true) ? $raw : 'PRODUCTION';
    }

    /**
     * Return base URL for the chosen environment.
     */
    private function baseUrl(string $env): string
    {
        return $env === 'PRODUCTION'
            ? 'https://api.cashfree.com'
            : 'https://sandbox.cashfree.com';
    }

    /**
     * Return (clientId, clientSecret) for the chosen environment.
     * Configure these in config/cashfree.php (and .env).
     *
     * Example config/cashfree.php:
     * return [
     *   'prod' => ['client_id' => env('CASHFREE_PROD_CLIENT_ID'), 'client_secret' => env('CASHFREE_PROD_CLIENT_SECRET')],
     *   'sandbox' => ['client_id' => env('CASHFREE_SANDBOX_CLIENT_ID'), 'client_secret' => env('CASHFREE_SANDBOX_CLIENT_SECRET')],
     * ];
     */
    private function creds(string $env): array
    {
        if ($env === 'PRODUCTION') {
            $id = (string) config('cashfree.client_id');
            $sec = (string) config('cashfree.client_secret');
        } else {
            $id = (string) config('cashfree.      ');
            $sec = (string) config('cashfree.sandbox_client_secret');
        }

        // Fallback to legacy flat keys if someone hasn’t split config yet.
        if ($id === '' || $sec === '') {
            $id  = (string) config('cashfree.client_id', $id);
            $sec = (string) config('cashfree.client_secret', $sec);
        }

        return [$id, $sec];
    }

    /**
     * Build required headers for v2023-08-01.
     */
    private function headers(string $clientId, string $clientSecret): array
    {
        return [
            'Accept'          => 'application/json',
            'Content-Type'    => 'application/json',
            'x-api-version'   => $this->apiVersion,
            'x-client-id'     => $clientId,
            'x-client-secret' => $clientSecret,
        ];
    }

    private function safeJson(?string $body)
    {
        if (!$body) return null;
        try { return json_decode($body, true); }
        catch (\Throwable) { return $body; }
    }
}
