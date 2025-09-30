<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PhonePeSdkV2Controller extends Controller
{
    /* ================== EDIT THESE 4 LINES ================== */
    private const ENV           = 'sandbox'; // 'sandbox' | 'production'
    private const CLIENT_ID     = 'TEST-M221AEW7ARW15_25082';            // ✅ fixed: removed extra TEST-
    private const CLIENT_SECRET = 'MjVhOTBmNjYtYjQ0OC00Y2FkLTlhZTEtMTJjMmVkZmIyYWVj';
    private const TIMEOUT_SEC   = 15;
    /* ======================================================== */

    // Base URLs
    private const OAUTH_BASE = [
        'sandbox'    => 'https://api-preprod.phonepe.com/apis/pg-sandbox',
        'production' => 'https://api.phonepe.com/apis/identity-manager',
    ];
    private const PG_BASE = [
        'sandbox'    => 'https://api-preprod.phonepe.com/apis/pg-sandbox',
        'production' => 'https://api.phonepe.com/apis/pg',
    ];

    // Endpoints
    private const EP_TOKEN        = '/v1/oauth/token';
    private const EP_CREATE_ORDER = '/checkout/v2/sdk/order';
    private const EP_ORDER_STATUS = '/checkout/v2/order/{merchantOrderId}/status';

    private string $env;
    private string $clientId;
    private string $clientSecret;
    private string $oauthBase;
    private string $pgBase;
    private int    $timeout;

    public function __construct()
    {
        $this->env          = self::ENV;
        $this->clientId     = self::CLIENT_ID;
        $this->clientSecret = self::CLIENT_SECRET;
        $this->oauthBase    = rtrim(self::OAUTH_BASE[$this->env] ?? '', '/');
        $this->pgBase       = rtrim(self::PG_BASE[$this->env] ?? '', '/');
        $this->timeout      = self::TIMEOUT_SEC;

        if (!$this->oauthBase || !$this->pgBase) {
            throw new \RuntimeException('PhonePe base URLs not defined for env: '.$this->env);
        }
    }

    /** 🔑 Always fetch a fresh OAuth token (form-urlencoded) */
    private function fetchToken(): string
    {
        $url = $this->oauthBase . self::EP_TOKEN;

        // We’ll try a couple of param variants to be robust.
        $attempts = [
            // Common current format:
            [
                'payload' => [
                    'client_id'      => $this->clientId,
                    'client_secret'  => $this->clientSecret,
                    'grant_type'     => 'client_credentials',
                    'client_version' => 'v2',
                ],
            ],
            // Fallback: without client_version
            [
                'payload' => [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type'    => 'client_credentials',
                ],
            ],
            // Legacy camelCase (some tenants)
            [
                'payload' => [
                    'clientId'     => $this->clientId,
                    'clientSecret' => $this->clientSecret,
                    'grantType'    => 'client_credentials',
                ],
            ],
        ];

        $lastStatus = null;
        $lastBody = null;

        foreach ($attempts as $i => $a) {
            try {
                $resp = Http::timeout($this->timeout)
                    ->asForm()
                    ->post($url, $a['payload']);

                $lastStatus = $resp->status();
                $lastBody   = $resp->json() ?? $resp->body();

                Log::info('PhonePe OAuth attempt '.($i+1), [
                    'status'  => $lastStatus,
                    'payload_keys' => array_keys($a['payload']),
                    'resp'    => $lastBody,
                ]);

                if ($resp->successful()) {
                    $data  = is_array($lastBody) ? $lastBody : (json_decode((string)$lastBody, true) ?? []);
                    $token = $data['access_token'] ?? $data['accessToken'] ?? $data['token'] ?? null;
                    if (!$token) {
                        throw new \RuntimeException('Token missing in success response');
                    }
                    return $token;
                }
            } catch (\Throwable $e) {
                Log::warning('PhonePe OAuth exception attempt '.($i+1), ['ex' => $e->getMessage()]);
            }
        }

        // Bubble up with full context
        $err = is_string($lastBody) ? $lastBody : json_encode($lastBody);
        throw new \RuntimeException("OAuth token failed: HTTP {$lastStatus} => {$err}");
    }

    /** GET /api/phonepe/token */
    public function getAuthToken()
    {
        try {
            $token = $this->fetchToken();
            return response()->json(['status' => 'ok', 'env' => $this->env, 'token' => $token]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'OAuth failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/phonepe/order
     * Body:
     * {
     *   "merchantOrderId":"order_123",
     *   "amount":149.50 OR "amountPaise":14950,
     *   "redirectUrl":"https://your.site/return",      // optional (web)
     *   "callbackUrl":"https://your.site/pp-webhook",  // optional (server)
     *   "validFor":600,
     *   "merchantUserId":"uid_abc",
     *   "note":"Zo Stream plan",
     *   "redirectMode":"GET" // or POST
     * }
     */
    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'merchantOrderId' => 'required|string|max:64',
            'amount'          => 'nullable|numeric|min:0.01',
            'amountPaise'     => 'nullable|integer|min:100',
            'callbackUrl'     => 'nullable|url',
            'validFor'        => 'nullable|integer|min:60|max:3600',
            'merchantUserId'  => 'nullable|string|max:64',
            'note'            => 'nullable|string|max:256',
            'redirectUrl'     => 'nullable|url',
            'redirectMode'    => ['nullable', Rule::in(['GET','POST'])],
        ]);

        try {
            $token = $this->fetchToken();
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'OAuth failed',
                'error'   => $e->getMessage(),
            ], 500);
        }

        $amountPaise = $validated['amountPaise'] ?? (int) round(($validated['amount'] ?? 0) * 100);
        if ($amountPaise < 100) {
            return response()->json(['status' => 'error', 'message' => 'Minimum amount is ₹1.00 (100 paise)'], 422);
        }

        // Build payload in PG_CHECKOUT shape
        $payload = [
            'merchantOrderId' => $validated['merchantOrderId'],
            'amount'          => $amountPaise,
            // 'expireAfter'   => $validated['validFor'] ?? 1200, // uncomment if your tenant uses expireAfter
            'paymentFlow'     => [
                'type'         => 'PG_CHECKOUT',
                'merchantUrls' => [
                    'redirectUrl' => $validated['redirectUrl'] ?? null,
                ],
            ],
        ];

        if (!empty($validated['callbackUrl']))    $payload['callbackUrl']    = $validated['callbackUrl'];
        if (!empty($validated['validFor']))       $payload['expireAfter']    = (int)$validated['validFor']; // some docs name this 'expireAfter'
        if (!empty($validated['merchantUserId'])) $payload['merchantUserId'] = $validated['merchantUserId'];
        if (!empty($validated['note']))           $payload['metaInfo']['udf1'] = $validated['note'];
        if (!empty($validated['redirectMode']))   $payload['paymentFlow']['merchantUrls']['redirectMethod'] = $validated['redirectMode'];

        // Clean nulls from nested arrays
        array_walk_recursive($payload, function (&$v) { if ($v === null) $v = null; });
        // (PhonePe usually tolerates missing keys; leaving nulls is fine or unset them if you prefer.)

        try {
            $url  = $this->pgBase . self::EP_CREATE_ORDER;
            $resp = Http::timeout($this->timeout)
                ->withHeaders(['Authorization' => 'O-Bearer '.$token]) // IMPORTANT
                ->asJson()
                ->post($url, $payload);

            $json = $resp->json();
            $raw  = $json ?? $resp->body();

            Log::info('PhonePe create order', ['status' => $resp->status(), 'req' => $payload, 'resp' => $raw]);

            if (!$resp->successful()) {
                return response()->json([
                    'status' => 'error',
                    'http'   => $resp->status(),
                    'body'   => $raw,
                ], 502);
            }

            return response()->json([
                'status'   => 'ok',
                'env'      => $this->env,
                'request'  => $payload,
                'response' => $json ?? $raw,
                'hints'    => [
                    'redirectUrl' => $json['paymentUrls']['redirectUrl'] ?? $json['redirectUrl'] ?? null,
                    'token'       => $json['token'] ?? $json['accessToken'] ?? null,
                    'state'       => $json['state'] ?? null,
                    'orderId'     => $json['orderId'] ?? null,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('PhonePe create order exception', ['ex' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Exception while creating order', 'error' => $e->getMessage()], 500);
        }
    }

    /** GET /api/phonepe/order/{merchantOrderId}/status */
    public function getOrderStatus(string $merchantOrderId)
    {
        try {
            $token = $this->fetchToken();
        } catch (\Throwable $e) {
            return response()->json([
                'success'  => false,
                'message' => 'OAuth failed',
                'error'   => $e->getMessage(),
            ], 500);
        }

        try {
            $url  = $this->pgBase . str_replace('{merchantOrderId}', urlencode($merchantOrderId), self::EP_ORDER_STATUS);
            $resp = Http::timeout($this->timeout)
                ->withHeaders(['Authorization' => 'O-Bearer '.$token])
                ->get($url);

            $json = $resp->json();
            $raw  = $json ?? $resp->body();

            Log::info('PhonePe order status', ['status' => $resp->status(), 'order' => $merchantOrderId, 'resp' => $raw]);

            if (!$resp->successful()) {
                return response()->json([
                    'success' => false,
                    'http'   => $resp->status(),
                    'body'   => $raw,
                ], 502);
            }

            return response()->json([
                'success'          => true,
                'env'             => $this->env,
                'merchantOrderId' => $merchantOrderId,
                'data'        => $json ?? $raw,
                'state'           => $json['state'] ?? null,
            ]);

        } catch (\Throwable $e) {
            Log::error('PhonePe order status exception', ['ex' => $e->getMessage(), 'order' => $merchantOrderId]);
            return response()->json(['success' => false, 'message' => 'Exception while fetching order status', 'error' => $e->getMessage()], 500);
        }
    }
}
