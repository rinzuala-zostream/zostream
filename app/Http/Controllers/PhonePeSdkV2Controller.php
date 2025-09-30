<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PhonePeSdkV2Controller extends Controller
{
    // ==== HARDCODED CONFIG (Sandbox) ====
    private string $baseSandbox = 'https://api.phonepe.com/apis/identity-manager';
    private string $baseOrder = 'https://api.phonepe.com/apis/pg';
    private string $clientId = 'M221AEW7ARW15';
    private string $clientSecret = '1d8c7b88-710d-4c48-a70a-cdd08c8cabac';

    /**
     * POST /api/phonepe/web-pay
     * Body: { amount: <paise>, merchantOrderId: "..." , expireAfter?: <secs> }
     */
    public function createSdkOrder(Request $req)
    {
        // Validate only expireAfter if provided
        $req->validate([
            'amount' => 'nullable|numeric|min:100', // paise
            'merchantOrderId' => 'nullable|string',
            'expireAfter' => 'nullable|integer|min:300|max:3600',
        ]);

        $amountPaise = $req->amount;
        $merchantOrderId = $req->merchantOrderId;
        $expireAfter = $req->input('expireAfter');

        try {
            // 1) OAuth token
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
                    'raw' => $tokenResp->json()
                ], 500);
            }

            $accessToken = $tokenResp->json('accessToken') ?? $tokenResp->json('access_token');
            if (!$accessToken) {
                return response()->json(['ok' => false, 'error' => 'AUTH_TOKEN_MISSING'], 500);
            }

            // ✅ If no order info passed → return only token
            if (empty($amountPaise) || empty($merchantOrderId)) {
                return response()->json([
                    'ok' => true,
                    'token' => $accessToken
                ]);
            }

            // 2a) SDK order
            $sdkUrl = $this->baseOrder . '/checkout/v2/sdk/order';
            $sdkPayload = [
                'merchantOrderId' => $merchantOrderId,
                'amount' => (int) $amountPaise,
                'paymentFlow' => ['type' => 'PG_CHECKOUT'],
            ];
            if (!is_null($expireAfter)) {
                $sdkPayload['expireAfter'] = (int) $expireAfter;
            }

            $sdkResp = Http::withHeaders([
                'Authorization' => 'O-Bearer ' . $accessToken,
            ])->acceptJson()->post($sdkUrl, $sdkPayload);

            if (!$sdkResp->successful()) {
                return response()->json([
                    'ok' => false,
                    'error' => 'SDK_ORDER_FAILED',
                    'raw' => $sdkResp->json()
                ], 500);
            }

            $sdkJson = $sdkResp->json();

            // 2b) Pay order (for redirect URL)
            $payUrl = $this->baseOrder . '/checkout/v2/pay';
            $payPayload = [
                'merchantOrderId' => $merchantOrderId,
                'amount' => (int) $amountPaise,
                'paymentFlow' => ['type' => 'PG_CHECKOUT'],
                'merchantUrls' => [
                    'redirectUrl' => route('phonepe.success', ['id' => $merchantOrderId]),
                ],
            ];
            if (!is_null($expireAfter)) {
                $payPayload['expireAfter'] = (int) $expireAfter;
            }

            $payResp = Http::withHeaders([
                'Authorization' => 'O-Bearer ' . $accessToken,
            ])->acceptJson()->post($payUrl, $payPayload);

            $redirectUrl = null;
            if ($payResp->successful()) {
                $redirectUrl = data_get($payResp->json(), 'data.redirectUrl') ?? data_get($payResp->json(), 'redirectUrl');
            }

            // ✅ Merge both results
            return response()->json([
                'ok' => true,
                'orderId' => $sdkJson['orderId'] ?? null,
                'state' => $sdkJson['state'] ?? null,
                'expireAt' => $sdkJson['expireAt'] ?? null,
                'token' => $sdkJson['token'] ?? null,
                'redirectUrl' => $redirectUrl,
            ]);

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
