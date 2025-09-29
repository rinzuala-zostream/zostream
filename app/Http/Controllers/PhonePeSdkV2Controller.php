<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PhonePeSdkV2Controller extends Controller
{
    // ==== HARDCODED CONFIG (Sandbox) ====
    private string $baseSandbox  = 'https://api-preprod.phonepe.com/apis/pg-sandbox';
    private string $clientId     = 'TEST-M221AEW7ARW15_25082';
    private string $clientSecret = 'MjVhOTBmNjYtYjQ0OC00Y2FkLTlhZTEtMTJjMmVkZmIyYWVj';

    // 1) OAuth helper (O-Bearer)
    private function fetchAuthToken(): ?string
    {
        try {
            $resp = Http::asForm()->post($this->baseSandbox . '/v1/oauth/token', [
                'client_version' => 1,
                'grant_type'     => 'client_credentials',
                'client_id'      => $this->clientId,
                'client_secret'  => $this->clientSecret,
            ]);

            if (!$resp->successful()) {
                Log::error('PhonePe OAuth failed', ['status' => $resp->status(), 'body' => $resp->body()]);
                return null;
            }

            $json = $resp->json();
            return $json['accessToken'] ?? $json['access_token'] ?? null;
        } catch (\Throwable $e) {
            Log::error('PhonePe OAuth exception', ['e' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * POST /api/phonepe/sdk-order
     * Body: {
     *   "amount": 100,                // paise
     *   "merchantOrderId": "order_...", 
     *   "expireAfter": 1200           // optional seconds
     * }
     *
     * Returns (example):
     * {
     *   "orderId": "OMO2509291639353360187324",
     *   "state": "PENDING",
     *   "expireAt": 1759316975337,
     *   "token": "eyJhbGciOi..."
     * }
     */
    public function createSdkOrder(Request $req)
    {
        $req->validate([
            'amount'          => 'required|integer|min:100',
            'merchantOrderId' => 'required|string',
            'expireAfter'     => 'nullable|integer|min:60',
        ]);

        $amountPaise     = (int) $req->amount;
        $merchantOrderId = $req->merchantOrderId;
        $expireAfter     = $req->input('expireAfter');

        // 2) Get O-Bearer
        $bearer = $this->fetchAuthToken();
        if (!$bearer) {
            return response()->json(['ok' => false, 'error' => 'AUTH_TOKEN_FAILED'], 500);
        }

        // 3) Create SDK order (NO REDIRECT)
        $payload = [
            'amount'          => $amountPaise,
            'merchantOrderId' => $merchantOrderId,
            'paymentFlow'     => ['type' => 'PG_CHECKOUT'],
            // Optional META/UDFs if you need:
            // 'metaInfo' => ['udf1' => '', 'udf2' => '', ...],
        ];
        if (!is_null($expireAfter)) {
            $payload['expireAfter'] = (int) $expireAfter;
        }

        try {
            $resp = Http::withHeaders([
                'Authorization' => 'O-Bearer ' . $bearer,
                'Content-Type'  => 'application/json',
            ])->post($this->baseSandbox . '/checkout/v2/sdk/order', $payload);

            if (!$resp->successful()) {
                return response()->json([
                    'ok'   => false,
                    'code' => $resp->status(),
                    'body' => $resp->json(),
                ], $resp->status());
            }

            $json     = $resp->json();
            $data     = $json['data'] ?? [];

            // Shape the response exactly like your sample:
            return response()->json([
                'orderId'  => $data['orderId']  ?? $data['merchantOrderId'] ?? $merchantOrderId,
                'state'    => $data['state']    ?? 'PENDING',
                'expireAt' => $data['expireAt'] ?? null,
                'token'    => $data['token']    ?? null,
            ]);

        } catch (\Throwable $e) {
            Log::error('PhonePe SDK order exception', ['e' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'SERVER_ERROR', 'message' => $e->getMessage()], 500);
        }
    }
}
