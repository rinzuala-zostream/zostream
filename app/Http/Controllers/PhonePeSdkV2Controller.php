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
            'amount' => 'required|integer|min:100', // paise
            'merchantOrderId' => 'required|string',
            'expireAfter' => 'nullable|integer|min:300|max:3600',
        ]);

        $amountPaise = (int) $req->amount;
        $merchantOrderId = $req->merchantOrderId;
        $expireAfter = $req->input('expireAfter');

        try {
            // 1) OAuth -> O-Bearer token
            $oauthUrl = $this->baseSandbox . '/v1/oauth/token';
            $tokenResp = Http::asForm()->post($oauthUrl, [
                'client_version' => 1,
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);
            if (!$tokenResp->successful()) {
                return response()->json(['ok' => false, 'error' => 'AUTH_TOKEN_FAILED', 'raw' => $tokenResp->json()], 500);
            }
            $accessToken = $tokenResp->json('accessToken') ?? $tokenResp->json('access_token');
            if (!$accessToken) {
                return response()->json(['ok' => false, 'error' => 'AUTH_TOKEN_MISSING'], 500);
            }

            // 2) Create SDK Order -> returns token
            $sdkUrl = $this->baseSandbox . '/checkout/v2/sdk/order';
            $payload = [
                'merchantOrderId' => $merchantOrderId,
                'amount' => $amountPaise,
                'paymentFlow' => ['type' => 'PG_CHECKOUT'],
            ];
            if (!is_null($expireAfter)) {
                $payload['expireAfter'] = (int) $expireAfter;
            }

            $sdkResp = Http::withHeaders([
                'Authorization' => 'O-Bearer ' . $accessToken,
            ])->acceptJson()->post($sdkUrl, $payload);

            if (!$sdkResp->successful()) {
                return response()->json(['ok' => false, 'error' => 'SDK_ORDER_FAILED', 'raw' => $sdkResp->json()], 500);
            }

            // ✅ This contains { orderId, state, expireAt, token }
            return response()->json($sdkResp->json());

        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'SERVER_ERROR', 'message' => $e->getMessage()], 500);
        }
    }

    public function success($id)
    {
        // TODO: Ideally verify with /checkout/v2/order/{merchantOrderId}/status before confirming
        return response()->json(['status' => 'success', 'merchantOrderId' => $id]);
    }
}
