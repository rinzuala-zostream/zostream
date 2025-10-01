<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class CashFreeController extends Controller
{
    /** @var string SANDBOX|PRODUCTION */
    private string $environment;
    private string $clientId;
    private string $clientSecret;
    private string $apiVersion = '2023-08-01';   // ← new version
    private string $baseUrl;                     // computed from env

    public function __construct()
    {
        // Put these in config/cashfree.php and .env
        $this->environment = strtoupper(config('cashfree.env', 'SANDBOX'));
        $this->clientId    = (string) config('cashfree.client_id');
        $this->clientSecret= (string) config('cashfree.client_secret');

        // v2023-08-01 base URLs
        // Sandbox:    https://sandbox.cashfree.com/pg
        // Production: https://api.cashfree.com/pg
        $this->baseUrl = $this->environment === 'PRODUCTION'
            ? 'https://api.cashfree.com'
            : 'https://sandbox.cashfree.com';  // default sandbox
    }

    /**
     * POST /api/cashfree/orders
     * Body must include:
     *  - order_amount (numeric), order_currency (INR), customer_details{customer_id, customer_email, customer_phone}
     *  - order_meta.return_url (optional). NOTE: notify_url is NOT supported on v2023-08-01.
     */
    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'customer_details.customer_id'    => 'required|string',
            'customer_details.customer_email' => 'required|email',
            'customer_details.customer_phone' => 'required|string',
            'order_meta.return_url'           => 'nullable|url',
            // 'order_meta.notify_url' is NOT supported on this API version
            'order_amount'                    => 'required|numeric|min:0.0',
            'order_currency'                  => 'required|string|size:3',
            'order_note'                      => 'nullable|string',
        ]);

        $client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => 15,
        ]);

        try {
            $resp = $client->post('/pg/orders', [
                'headers' => $this->headers(),
                'json'    => $validated,
            ]);

            $data = json_decode($resp->getBody()->getContents(), true);

            // Expect: order_id + payment_session_id (use this in Android SDK)
            // Docs: Create Order returns payment_session_id for initiating payments. :contentReference[oaicite:1]{index=1}
            return response()->json([
                'status'  => 'success',
                'data'    => Arr::only($data, ['order_id', 'payment_session_id']) + ['raw' => $data],
                'env'     => $this->environment,
            ]);
        } catch (RequestException $e) {
            $body = $e->getResponse()?->getBody()?->getContents();
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
                'body'    => $this->safeJson($body),
            ], $e->getResponse()?->getStatusCode() ?: 500);
        }
    }

    /**
     * GET /api/cashfree/order-status?order_id=...
     * Preferred verification on v2023-08-01:
     *   - GET /pg/orders/{order_id} and check order_status == "PAID"
     * Optionally, you can also inspect /pg/orders/{order_id}/payments.
     */
    public function checkPayment(Request $request)
    {
        $request->validate([
            'order_id' => 'required|string',
        ]);

        $orderId = $request->query('order_id');

        $client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => 15,
        ]);

        try {
            // 1) Verify order status
            // Docs: Get Order returns full entity; use it to check status. :contentReference[oaicite:2]{index=2}
            $respOrder = $client->get("/orders/{$orderId}", [
                'headers' => $this->headers(),
            ]);
            $order = json_decode($respOrder->getBody()->getContents(), true);

            $orderStatus = $order['order_status'] ?? 'UNKNOWN';

            $isPaid = strtoupper($orderStatus) === 'PAID';

            // 2) (Optional) Also fetch payments list for more granular info (auth code, rrn, etc.)
            // Docs: GET /orders/{order_id}/payments. :contentReference[oaicite:3]{index=3}
            $payments = [];
            try {
                $respPayments = $client->get("/orders/{$orderId}/payments", [
                    'headers' => $this->headers(),
                ]);
                $payments = json_decode($respPayments->getBody()->getContents(), true);
            } catch (\Throwable $ignore) {
                // keep going with just the order status
            }

            return response()->json([
                'success' => $isPaid,
                'code'    => $isPaid ? 'PAYMENT_SUCCESS' : $orderStatus,
                'data'    => [
                    'state'    => $isPaid ? 'COMPLETED' : 'PENDING', // your app logic can branch on this
                    'order'    => $order,
                    'payments' => $payments,
                ],
            ]);
        } catch (RequestException $e) {
            $body = $e->getResponse()?->getBody()?->getContents();
            return response()->json([
                'success' => false,
                'code'    => 'EXCEPTION',
                'message' => $e->getMessage(),
                'body'    => $this->safeJson($body),
            ], $e->getResponse()?->getStatusCode() ?: 500);
        }
    }

    private function headers(): array
    {
        // Required headers for v2023-08-01. :contentReference[oaicite:4]{index=4}
        return [
            'Accept'         => 'application/json',
            'Content-Type'   => 'application/json',
            'x-api-version'  => $this->apiVersion,
            'x-client-id'    => $this->clientId,
            'x-client-secret'=> $this->clientSecret,
        ];
    }

    private function safeJson(?string $body)
    {
        if (!$body) return null;
        try { return json_decode($body, true); } catch (\Throwable) { return $body; }
    }
}
