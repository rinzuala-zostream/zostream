<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PhonePeSdkV2Controller extends Controller
{
    // ==== HARDCODED CONFIG (Sandbox) ====
    private string $baseSandbox = 'https://api-preprod.phonepe.com/apis/pg-sandbox';
    private string $clientId = 'TEST-M221AEW7ARW15_25082';
    private string $clientSecret = 'MjVhOTBmNjYtYjQ0OC00Y2FkLTlhZTEtMTJjMmVkZmIyYWVj';

    /**
     * POST /api/phonepe/web-pay
     * Body: { amount: <paise>, merchantOrderId: "..." , expireAfter?: <secs> }
     */
    public function createSdkOrder(Request $req)
    {
        $req->validate([
            'amount' => 'required|integer|min:100',  // paise
            'merchantOrderId' => 'required|string',
            'expireAfter' => 'nullable|integer|min:60',
        ]);

        $amountPaise = (int) $req->amount;
        $merchantOrderId = $req->merchantOrderId;
        $expireAfter = $req->input('expireAfter');

        try {
            // 1) Get OAuth Token
            $oauthUrl = $this->baseSandbox . '/v1/oauth/token';
            $tokenResp = Http::asForm()->post($oauthUrl, [
                'client_version' => 1,
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

            if (!$tokenResp->successful()) {
                return response()->json([
                    'ok' => false,
                    'error' => 'AUTH_TOKEN_FAILED',
                    'raw' => $tokenResp->json(),
                ], 500);
            }

            $tokJson = $tokenResp->json();
            $accessToken = $tokJson['accessToken'] ?? $tokJson['access_token'] ?? null;
            if (!$accessToken) {
                return response()->json([
                    'ok' => false,
                    'error' => 'AUTH_TOKEN_MISSING',
                    'raw' => $tokJson,
                ], 500);
            }

            // 2) Create Order
            $payUrl = $this->baseSandbox . '/checkout/v2/pay';
            $payload = [
                'merchantOrderId' => $merchantOrderId,
                'amount' => $amountPaise,
                'paymentFlow' => ['type' => 'PG_CHECKOUT'],
                'merchantUrls' => [
                    'redirectUrl' => route('phonepe.success', ['id' => $merchantOrderId]),
                    // 'callbackUrl' => route('phonepe.callback'),
                ],
            ];
            if (!is_null($expireAfter)) {
                $payload['expireAfter'] = (int) $expireAfter;
            }

            $payResp = Http::withHeaders([
                'Authorization' => 'O-Bearer ' . $accessToken,
            ])->acceptJson()->post($payUrl, $payload);

            if (!$payResp->successful()) {
                return response()->json([
                    'ok' => false,
                    'error' => 'PAY_REQUEST_FAILED',
                    'raw' => $payResp->json(),
                ], 500);
            }

            // ✅ Return the raw PhonePe response
            return response()->json($payResp->json());

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'SERVER_ERROR',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function success($id)
    {
        // TODO: Ideally verify with /checkout/v2/order/{merchantOrderId}/status before confirming
        return response()->json(['status' => 'success', 'merchantOrderId' => $id]);
    }
}
