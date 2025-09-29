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

    /**
     * POST /api/phonepe/web-pay
     * Body: { amount: <paise>, merchantOrderId: "..." , expireAfter?: <secs> }
     */
    public function createSdkOrder(Request $req)
    {
        $req->validate([
            'amount'          => 'required|integer|min:100',  // paise
            'merchantOrderId' => 'required|string',
            'expireAfter'     => 'nullable|integer|min:60',
        ]);

        $amountPaise     = (int) $req->amount;
        $merchantOrderId = $req->merchantOrderId;
        $expireAfter     = $req->input('expireAfter'); // optional

        try {
            // 1) OAuth: form-urlencoded
            $oauthUrl = $this->baseSandbox . '/v1/oauth/token';
            $tokenResp = Http::asForm()->post($oauthUrl, [
                'client_version' => 1,
                'grant_type'     => 'client_credentials',
                'client_id'      => $this->clientId,
                'client_secret'  => $this->clientSecret,
            ]);

            if (!$tokenResp->successful()) {
                Log::error('PhonePe token request failed', [
                    'status' => $tokenResp->status(),
                    'body'   => $tokenResp->body(),
                ]);
                return response()->json(['ok' => false, 'error' => 'AUTH_TOKEN_FAILED'], 500);
            }

            $tokJson     = $tokenResp->json();
            $accessToken = $tokJson['accessToken'] ?? $tokJson['access_token'] ?? null;
            if (!$accessToken) {
                Log::error('PhonePe token missing in response', ['json' => $tokJson]);
                return response()->json(['ok' => false, 'error' => 'AUTH_TOKEN_MISSING'], 500);
            }

            // 2) Create Pay (web redirect) — merchantUrls MUST be top-level
            $payUrl = $this->baseSandbox . '/checkout/v2/pay';
            $payload = [
                'merchantOrderId' => $merchantOrderId,
                'amount'          => $amountPaise,
                'paymentFlow'     => [
                    'type' => 'PG_CHECKOUT',
                    // 'message' => 'Optional message',
                ],
                'merchantUrls'    => [
                    'redirectUrl' => route('phonepe.success', ['id' => $merchantOrderId]),
                    // 'callbackUrl' => route('phonepe.callback'), // optional server-to-server
                ],
            ];
            if (!is_null($expireAfter)) {
                $payload['expireAfter'] = (int) $expireAfter;
            }

            $payResp = Http::withHeaders([
                'Authorization' => 'O-Bearer ' . $accessToken,
            ])->acceptJson()->post($payUrl, $payload);

            if (!$payResp->successful()) {
                Log::error('PhonePe PAY request failed', [
                    'status' => $payResp->status(),
                    'body'   => $payResp->body(),
                ]);
                return response()->json(['ok' => false, 'error' => 'PAY_REQUEST_FAILED'], 500);
            }

            $payJson     = $payResp->json();
            $redirectUrl = data_get($payJson, 'data.redirectUrl') ?? data_get($payJson, 'redirectUrl');

            if (!$redirectUrl) {
                Log::error('PhonePe redirectUrl missing', ['json' => $payJson]);
                return response()->json(['ok' => false, 'error' => 'REDIRECT_URL_MISSING', 'raw' => $payJson], 500);
            }

            // 3) Redirect user to PhonePe checkout
            return redirect()->away($redirectUrl);

        } catch (\Throwable $e) {
            Log::error('PhonePe createSdkOrder exception', ['e' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'SERVER_ERROR', 'message' => $e->getMessage()], 500);
        }
    }

    public function success($id)
    {
        // TODO: Ideally verify with /checkout/v2/order/{merchantOrderId}/status before confirming
        return response()->json(['status' => 'success', 'merchantOrderId' => $id]);
    }
}
