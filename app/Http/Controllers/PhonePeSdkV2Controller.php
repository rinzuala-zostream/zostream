<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PhonePeSdkV2Controller extends Controller
{
    // ==== LIVE CONFIG (Hardcoded) ====
    private string $pgBaseLive   = 'https://api.phonepe.com/apis/pg';
    private string $merchantId   = 'M221AEW7ARW15';
    private string $keyIndex     = '1';   // use "1" unless you’ve rotated keys
    private string $saltKey      = '1d8c7b88-710d-4c48-a70a-cdd08c8cabac';

    /** Helper: build checksum for payload (POST /v1/pay) */
    private function xVerifyForPayload(string $endpointPath, array $payload): string
    {
        $json   = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $digest = hash('sha256', $json . $endpointPath . $this->saltKey, true);
        return base64_encode($digest) . "###" . $this->keyIndex;
    }

    /** Helper: build checksum for status (GET /v1/status/{mid}/{orderId}) */
    private function xVerifyForPath(string $endpointPath): string
    {
        $digest = hash('sha256', $endpointPath . $this->saltKey, true);
        return base64_encode($digest) . "###" . $this->keyIndex;
    }

    /** Convert rupees/paise into paise integer */
    private function toPaise($amount): int
    {
        if (is_null($amount) || $amount === '') return 0;
        if (is_numeric($amount) && (strpos((string)$amount, '.') !== false || (int)$amount < 1000)) {
            return (int) round(((float)$amount) * 100); // treat as rupees
        }
        return (int) $amount; // already paise
    }

    /**
     * POST /api/phonepe/web-pay
     * Body: { amount: number, merchantOrderId: string, expireAfter?: int, flow?: "PAY_PAGE" | "PG_CHECKOUT" }
     */
    public function createSdkOrder(Request $req)
    {
        $req->validate([
            'amount'          => 'required|numeric|min:0.5',
            'merchantOrderId' => 'required|string',
            'expireAfter'     => 'nullable|integer|min:300|max:3600',
            'flow'            => 'nullable|string|in:PAY_PAGE,PG_CHECKOUT',
        ]);

        $merchantOrderId = $req->string('merchantOrderId');
        $amountPaise     = $this->toPaise($req->input('amount'));
        $expireAfter     = $req->input('expireAfter');
        $flow            = $req->input('flow', 'PAY_PAGE');

        try {
            $endpoint = '/v1/pay';
            $url      = rtrim($this->pgBaseLive, '/') . $endpoint;

            $payload = [
                'merchantId'      => $this->merchantId,
                'merchantOrderId' => (string) $merchantOrderId,
                'amount'          => $amountPaise,
                'merchantUrls'    => [
                    'redirectUrl' => route('phonepe.success', ['id' => $merchantOrderId]),
                    'callbackUrl' => route('phonepe.success', ['id' => $merchantOrderId]),
                ],
                'paymentInstrument' => [
                    'type' => $flow === 'PG_CHECKOUT' ? 'PG_CHECKOUT' : 'PAY_PAGE',
                ],
            ];
            if (!is_null($expireAfter)) {
                $payload['expireAfter'] = (int) $expireAfter;
            }

            $xVerify = $this->xVerifyForPayload($endpoint, $payload);

            $resp = Http::withHeaders([
                'Content-Type'  => 'application/json',
                'X-VERIFY'      => $xVerify,
                'X-MERCHANT-ID' => $this->merchantId,
            ])->post($url, $payload);

            $body = $resp->json();

            if (!$resp->successful() || !data_get($body, 'success', false)) {
                return response()->json([
                    'ok'    => false,
                    'error' => 'LIVE_PAY_FAILED',
                    'raw'   => $body ?? $resp->body(),
                ], 502);
            }

            $redirectUrl = data_get($body, 'data.instrumentResponse.redirectInfo.url')
                        ?? data_get($body, 'data.redirectUrl');

            return response()->json([
                'ok'          => true,
                'mode'        => 'live',
                'orderId'     => data_get($body, 'data.merchantTransactionId') ?? (string) $merchantOrderId,
                'redirectUrl' => $redirectUrl,
                'raw'         => $body,
            ]);
        } catch (\Throwable $e) {
            Log::error('PhonePe LIVE pay error: '.$e->getMessage());
            return response()->json([
                'ok'    => false,
                'error' => 'SERVER_ERROR',
                'msg'   => $e->getMessage(),
            ], 500);
        }
    }

    /** GET /phonepe/status/{id} */
    public function status(string $id)
    {
        try {
            $endpoint = "/v1/status/{$this->merchantId}/{$id}";
            $url      = rtrim($this->pgBaseLive, '/') . $endpoint;

            $xVerify = $this->xVerifyForPath($endpoint);

            $resp = Http::withHeaders([
                'Content-Type'  => 'application/json',
                'X-VERIFY'      => $xVerify,
                'X-MERCHANT-ID' => $this->merchantId,
            ])->get($url);

            $body = $resp->json();

            if (!$resp->successful() || !data_get($body, 'success', false)) {
                return response()->json([
                    'ok'    => false,
                    'error' => 'LIVE_STATUS_FAILED',
                    'raw'   => $body ?? $resp->body(),
                ], 502);
            }

            return response()->json([
                'ok'    => true,
                'state' => data_get($body, 'data.state') ?? null, // CREATED / PENDING / COMPLETED / FAILED
                'raw'   => $body,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'    => false,
                'error' => 'SERVER_ERROR',
                'msg'   => $e->getMessage(),
            ], 500);
        }
    }

    public function success($id)
    {
        // Better: call status() internally and check COMPLETED
        return response()->json(['status' => 'success', 'merchantOrderId' => $id]);
    }
}
