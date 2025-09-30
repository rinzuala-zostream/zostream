<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PhonePeSdkV2Controller extends Controller
{
    // ===== MODE: 'sandbox' or 'live' =====
    private string $mode = 'live'; // <-- change to 'sandbox' if needed

    // ===== BASE URLs =====
    // Sandbox
    private string $idBaseSandbox = 'https://api-preprod.phonepe.com/apis/identity-manager';
    private string $pgBaseSandbox = 'https://api-preprod.phonepe.com/apis/pg-sandbox';
    // Live
    private string $pgBaseLive    = 'https://api.phonepe.com/apis/pg';

    // ===== HARDCODED CREDS (reusing your fields) =====
    // For SANDBOX: client_id / client_secret (OAuth)
    // For LIVE:    merchantId (use clientId), saltKey (use clientSecret), keyIndex
    private string $clientId     = 'M221AEW7ARW15';                         // LIVE: merchantId
    private string $clientSecret = '1d8c7b88-710d-4c48-a70a-cdd08c8cabac';  // LIVE: saltKey
    private string $keyIndex     = '1';                                     // LIVE key index

    // ===== Helpers =====
    private function toPaise($amount): int
    {
        if ($amount === null || $amount === '') return 0;
        if (is_numeric($amount) && (strpos((string)$amount, '.') !== false || (int)$amount < 1000)) {
            return (int) round(((float)$amount) * 100); // treat as rupees
        }
        return (int) $amount; // assume paise
    }

    // Checksum for JSON payload endpoints (LIVE)
    private function xVerifyForPayload(string $endpointPath, array $payload): string
    {
        $json   = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $digest = hash('sha256', $json . $endpointPath . $this->clientSecret, true); // saltKey = clientSecret
        return base64_encode($digest) . "###" . $this->keyIndex;
    }

    // Checksum for path-only endpoints (LIVE)
    private function xVerifyForPath(string $endpointPath): string
    {
        $digest = hash('sha256', $endpointPath . $this->clientSecret, true); // saltKey = clientSecret
        return base64_encode($digest) . "###" . $this->keyIndex;
    }

    /**
     * POST /api/phonepe/web-pay
     * Body: { amount: <paise or rupees>, merchantOrderId: "..." , expireAfter?: <secs>, flow?: "PG_CHECKOUT"|"PAY_PAGE" }
     *
     * - SANDBOX:
     *    - If amount/orderId missing => returns OAuth token only (back-compat with your current behavior)
     *    - Else uses /checkout/v2/sdk/order then /checkout/v2/pay with O-Bearer
     * - LIVE:
     *    - No OAuth; signs /checkout/v2/pay with X-VERIFY and returns redirectUrl
     */
    public function createSdkOrder(Request $req)
    {
        // Keep your original relaxed validation; we handle strictness per mode below
        $req->validate([
            'amount'          => 'nullable|numeric|min:0.5',
            'merchantOrderId' => 'nullable|string',
            'expireAfter'     => 'nullable|integer|min:300|max:3600',
            'flow'            => 'nullable|string|in:PG_CHECKOUT,PAY_PAGE',
        ]);

        $amountPaise     = $this->toPaise($req->input('amount'));
        $merchantOrderId = $req->input('merchantOrderId');
        $expireAfter     = $req->input('expireAfter');
        $flow            = $req->input('flow', 'PAY_PAGE'); // default PAY_PAGE

        try {
            if ($this->mode === 'live') {
                // ===== LIVE (no OAuth) =====
                if (empty($amountPaise) || empty($merchantOrderId)) {
                    return response()->json([
                        'ok' => false, 'error' => 'MISSING_FIELDS',
                        'message' => 'merchantOrderId and amount are required in LIVE mode.'
                    ], 422);
                }

                $endpoint = '/checkout/v2/pay';
                $url      = rtrim($this->pgBaseLive, '/') . $endpoint;

                $payload = [
                    // include merchantId in payload for checkout v2 live
                    'merchantId'      => $this->clientId,              // merchantId
                    'merchantOrderId' => (string) $merchantOrderId,
                    'amount'          => $amountPaise,
                    'paymentFlow'     => ['type' => $flow === 'PG_CHECKOUT' ? 'PG_CHECKOUT' : 'PAY_PAGE'],
                    'merchantUrls'    => [
                        'redirectUrl' => route('phonepe.success', ['id' => $merchantOrderId]),
                        'callbackUrl' => route('phonepe.success', ['id' => $merchantOrderId]),
                    ],
                ];
                if (!is_null($expireAfter)) {
                    $payload['expireAfter'] = (int) $expireAfter;
                }

                $xVerify = $this->xVerifyForPayload($endpoint, $payload);

                $resp = Http::withHeaders([
                    'Content-Type'  => 'application/json',
                    'X-VERIFY'      => $xVerify,
                    'X-MERCHANT-ID' => $this->clientId,
                ])->post($url, $payload);

                $body = $resp->json();
                if (!$resp->successful() || !data_get($body, 'success', false)) {
                    return response()->json([
                        'ok' => false, 'error' => 'LIVE_PAY_FAILED', 'raw' => $body ?? $resp->body()
                    ], 502);
                }

                $redirectUrl = data_get($body, 'data.redirectUrl')
                             ?? data_get($body, 'data.instrumentResponse.redirectInfo.url');

                return response()->json([
                    'ok'          => true,
                    'mode'        => 'live',
                    'orderId'     => data_get($body, 'data.orderId')
                                   ?? data_get($body, 'data.merchantTransactionId')
                                   ?? (string) $merchantOrderId,
                    'redirectUrl' => $redirectUrl,
                    'raw'         => $body,
                ]);
            }

            // ===== SANDBOX (OAuth + checkout v2) =====
            // 1) OAuth
            $oauthUrl  = rtrim($this->idBaseSandbox, '/') . '/v1/oauth/token';
            $tokenResp = Http::asForm()->post($oauthUrl, [
                'client_version' => 1,
                'grant_type'     => 'client_credentials',
                'client_id'      => $this->clientId,
                'client_secret'  => $this->clientSecret,
            ]);

            if (!$tokenResp->successful()) {
                return response()->json([
                    'ok' => false, 'error' => 'AUTH_TOKEN_FAILED', 'raw' => $tokenResp->json()
                ], 500);
            }

            $accessToken = $tokenResp->json('accessToken') ?? $tokenResp->json('access_token');
            if (!$accessToken) {
                return response()->json(['ok' => false, 'error' => 'AUTH_TOKEN_MISSING'], 500);
            }

            // If no order info -> return token (your existing behavior)
            if (empty($amountPaise) || empty($merchantOrderId)) {
                return response()->json(['ok' => true, 'token' => $accessToken]);
            }

            // 2a) SDK order (checkout v2)
            $sdkUrl = rtrim($this->pgBaseSandbox, '/') . '/checkout/v2/sdk/order';
            $sdkPayload = [
                'merchantOrderId' => $merchantOrderId,
                'amount'          => $amountPaise,
                'paymentFlow'     => ['type' => 'PG_CHECKOUT'],
            ];
            if (!is_null($expireAfter)) $sdkPayload['expireAfter'] = (int) $expireAfter;

            $sdkResp = Http::withHeaders([
                'Authorization' => 'O-Bearer ' . $accessToken,
            ])->acceptJson()->post($sdkUrl, $sdkPayload);

            if (!$sdkResp->successful()) {
                return response()->json([
                    'ok' => false, 'error' => 'SDK_ORDER_FAILED', 'raw' => $sdkResp->json()
                ], 500);
            }
            $sdkJson = $sdkResp->json();

            // 2b) PAY (for redirect URL)
            $payUrl = rtrim($this->pgBaseSandbox, '/') . '/checkout/v2/pay';
            $payPayload = [
                'merchantOrderId' => $merchantOrderId,
                'amount'          => $amountPaise,
                'paymentFlow'     => ['type' => 'PG_CHECKOUT'],
                'merchantUrls'    => [
                    'redirectUrl' => route('phonepe.success', ['id' => $merchantOrderId]),
                ],
            ];
            if (!is_null($expireAfter)) $payPayload['expireAfter'] = (int) $expireAfter;

            $payResp = Http::withHeaders([
                'Authorization' => 'O-Bearer ' . $accessToken,
            ])->acceptJson()->post($payUrl, $payPayload);

            $redirectUrl = null;
            if ($payResp->successful()) {
                $redirectUrl = data_get($payResp->json(), 'data.redirectUrl')
                             ?? data_get($payResp->json(), 'redirectUrl');
            }

            return response()->json([
                'ok'         => true,
                'mode'       => 'sandbox',
                'orderId'    => $sdkJson['orderId'] ?? null,
                'state'      => $sdkJson['state'] ?? null,
                'expireAt'   => $sdkJson['expireAt'] ?? null,
                'token'      => $sdkJson['token'] ?? null,
                'redirectUrl'=> $redirectUrl,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false, 'error' => 'SERVER_ERROR', 'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ===== Optional helpers for LIVE (matching your endpoints list) =====

    // GET /checkout/v2/order/{merchantOrderId}/status   (LIVE)
    public function orderStatus(string $merchantOrderId)
    {
        try {
            $endpoint = "/checkout/v2/order/{$merchantOrderId}/status";
            $url      = rtrim($this->pgBaseLive, '/') . $endpoint;

            $xVerify = $this->xVerifyForPath($endpoint);

            $resp = Http::withHeaders([
                'Content-Type'  => 'application/json',
                'X-VERIFY'      => $xVerify,
                'X-MERCHANT-ID' => $this->clientId,
            ])->get($url);

            $body = $resp->json();
            if (!$resp->successful() || !data_get($body, 'success', false)) {
                return response()->json(['ok'=>false,'error'=>'LIVE_ORDER_STATUS_FAILED','raw'=>$body ?? $resp->body()], 502);
            }

            return response()->json([
                'ok'    => true,
                'state' => data_get($body, 'data.state'),
                'raw'   => $body,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok'=>false,'error'=>'SERVER_ERROR','message'=>$e->getMessage()], 500);
        }
    }

    // POST /payments/v2/refund  (LIVE)
    public function refund(Request $req)
    {
        $req->validate([
            'merchantOrderId'  => 'required|string',
            'merchantRefundId' => 'required|string',
            'amount'           => 'required|numeric|min:0.5',
            'reason'           => 'nullable|string',
        ]);

        try {
            $endpoint = '/payments/v2/refund';
            $url      = rtrim($this->pgBaseLive, '/') . $endpoint;

            $payload = [
                'merchantId'       => $this->clientId,
                'merchantOrderId'  => (string) $req->merchantOrderId,
                'merchantRefundId' => (string) $req->merchantRefundId,
                'amount'           => $this->toPaise($req->amount),
                'reason'           => $req->input('reason', 'Customer requested refund'),
            ];

            $xVerify = $this->xVerifyForPayload($endpoint, $payload);

            $resp = Http::withHeaders([
                'Content-Type'  => 'application/json',
                'X-VERIFY'      => $xVerify,
                'X-MERCHANT-ID' => $this->clientId,
            ])->post($url, $payload);

            $body = $resp->json();
            if (!$resp->successful() || !data_get($body, 'success', false)) {
                return response()->json(['ok'=>false,'error'=>'LIVE_REFUND_FAILED','raw'=>$body ?? $resp->body()], 502);
            }

            return response()->json(['ok'=>true, 'raw'=>$body]);
        } catch (\Throwable $e) {
            return response()->json(['ok'=>false,'error'=>'SERVER_ERROR','message'=>$e->getMessage()], 500);
        }
    }

    // GET /payments/v2/refund/{merchantRefundId}/status  (LIVE)
    public function refundStatus(string $merchantRefundId)
    {
        try {
            $endpoint = "/payments/v2/refund/{$merchantRefundId}/status";
            $url      = rtrim($this->pgBaseLive, '/') . $endpoint;

            $xVerify = $this->xVerifyForPath($endpoint);

            $resp = Http::withHeaders([
                'Content-Type'  => 'application/json',
                'X-VERIFY'      => $xVerify,
                'X-MERCHANT-ID' => $this->clientId,
            ])->get($url);

            $body = $resp->json();
            if (!$resp->successful() || !data_get($body, 'success', false)) {
                return response()->json(['ok'=>false,'error'=>'LIVE_REFUND_STATUS_FAILED','raw'=>$body ?? $resp->body()], 502);
            }

            return response()->json(['ok'=>true, 'raw'=>$body]);
        } catch (\Throwable $e) {
            return response()->json(['ok'=>false,'error'=>'SERVER_ERROR','message'=>$e->getMessage()], 500);
        }
    }

    // Your existing success hook (keep as-is; consider calling orderStatus() internally)
    public function success($id)
    {
        return response()->json(['status' => 'success', 'merchantOrderId' => $id]);
    }
}
