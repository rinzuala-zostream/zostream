<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class PhonePeSdkV2Controller extends Controller
{
    /** ========= Public Endpoints ========= */
    private const OAUTH_BASE = [
        'sandbox' => 'https://api-preprod.phonepe.com/apis/pg-sandbox',
        'production' => 'https://api.phonepe.com/apis/identity-manager',
    ];
    private const PG_BASE = [
        'sandbox' => 'https://api-preprod.phonepe.com/apis/pg-sandbox',
        'production' => 'https://api.phonepe.com/apis/pg',
    ];

    /** ========= Paths ========= */
    private const EP_TOKEN = '/v1/oauth/token';
    private const EP_CREATE_ORDER = '/checkout/v2/sdk/order';
    private const EP_ORDER_STATUS = '/checkout/v2/order/{merchantOrderId}/status';


    private function resolveEnv(Request $request): string
    {
        $h = strtolower(trim((string) $request->header('X-PP-Env', '')));
        $p = strtolower(trim((string) $request->input('env', '')));
        $raw = $h ?: $p ?: strtolower((string) config('phonepe.env', 'production'));
        return in_array($raw, ['sandbox', 'production'], true) ? $raw : 'production';
    }

    private function oauthBase(string $env): string
    {
        return rtrim(self::OAUTH_BASE[$env] ?? '', '/');
    }

    private function pgBase(string $env): string
    {
        return rtrim(self::PG_BASE[$env] ?? '', '/');
    }

    private function creds(string $env): array
    {
        if ($env === 'production') {
            $id = (string) config('phonepe.client_id');
            $sec = (string) config('phonepe.client_secret');
        } else {
            $id = (string) config('phonepe.sandbox_client_id');
            $sec = (string) config('phonepe.sandbox_client_secret');
        }
        return [$id, $sec];
    }

    private function timeout(): int
    {
        return (int) config('phonepe.timeout', 15);
    }


    private function fetchToken(string $env, string $clientId, string $clientSecret): string
    {
        $url = $this->oauthBase($env) . self::EP_TOKEN;
        if ($url === '') {
            throw new \RuntimeException("PhonePe OAuth base URL missing for env: {$env}");
        }

        $attempts = [
            // Preferred/current
            [
                'payload' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'client_credentials',
                    'client_version' => 'v2',
                ]
            ],
            // Without client_version
            [
                'payload' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'client_credentials',
                ]
            ],
            // Legacy camelCase
            [
                'payload' => [
                    'clientId' => $clientId,
                    'clientSecret' => $clientSecret,
                    'grantType' => 'client_credentials',
                ]
            ],
        ];

        $lastStatus = null;
        $lastBody = null;

        foreach ($attempts as $i => $a) {
            try {
                $resp = Http::timeout($this->timeout())
                    ->asForm()
                    ->post($url, $a['payload']);

                $lastStatus = $resp->status();
                $lastBody = $resp->json() ?? $resp->body();

                Log::info('PhonePe OAuth attempt ' . ($i + 1), [
                    'env' => $env,
                    'status' => $lastStatus,
                    'payloadKeys' => array_keys($a['payload']),
                    'resp' => is_array($lastBody) ? $lastBody : (string) $lastBody,
                ]);

                if ($resp->successful()) {
                    $data = is_array($lastBody) ? $lastBody : (json_decode((string) $lastBody, true) ?? []);
                    $token = $data['access_token'] ?? $data['accessToken'] ?? $data['token'] ?? null;
                    if (!$token) {
                        throw new \RuntimeException('Token missing in success response');
                    }
                    return $token;
                }
            } catch (\Throwable $e) {
                Log::warning('PhonePe OAuth exception attempt ' . ($i + 1), ['env' => $env, 'ex' => $e->getMessage()]);
            }
        }

        $err = is_string($lastBody) ? $lastBody : json_encode($lastBody);
        throw new \RuntimeException("OAuth token failed (env {$env}): HTTP {$lastStatus} => {$err}");
    }

    /** GET /api/phonepe/token (per-request env) */
    public function getAuthToken(Request $request)
    {
        $env = $this->resolveEnv($request);
        [$clientId, $clientSecret] = $this->creds($env);

        try {
            $token = $this->fetchToken($env, $clientId, $clientSecret);
            return response()->json([
                'status' => 'ok',
                'env' => $env,
                'token' => $token,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'env' => $env,
                'message' => 'OAuth failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'merchantOrderId' => 'required|string|max:64',
            'amount' => 'nullable|numeric|min:0.01',
            'amountPaise' => 'nullable|integer|min:100',
            'callbackUrl' => 'nullable|url',
            'validFor' => 'nullable|integer|min:60|max:3600',
            'merchantUserId' => 'nullable|string|max:64',
            'note' => 'nullable|string|max:256',
            'redirectUrl' => 'nullable|url',
            'redirectMode' => ['nullable', Rule::in(['GET', 'POST'])],
            'env' => ['nullable', Rule::in(['sandbox', 'production', 'SANDBOX', 'PRODUCTION'])],
        ]);

        // Resolve env + creds
        $env = $this->resolveEnv($request);
        [$clientId, $clientSecret] = $this->creds($env);
        $pgBase = $this->pgBase($env);
        if ($pgBase === '') {
            return response()->json(['status' => 'error', 'message' => "PhonePe PG base URL missing for env: {$env}"], 500);
        }

        // OAuth
        try {
            $token = $this->fetchToken($env, $clientId, $clientSecret);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'env' => $env,
                'message' => 'OAuth failed',
                'error' => $e->getMessage(),
            ], 500);
        }

        // Amount
        $amountPaise = $validated['amountPaise'] ?? (int) round(($validated['amount'] ?? 0) * 100);
        if ($amountPaise < 100) {
            return response()->json(['status' => 'error', 'message' => 'Minimum amount is ₹1.00 (100 paise)'], 422);
        }

        // Payload (PG_CHECKOUT)
        $payload = [
            'merchantOrderId' => $validated['merchantOrderId'],
            'amount' => $amountPaise,
            'paymentFlow' => [
                'type' => 'PG_CHECKOUT',
                'merchantUrls' => [
                    'redirectUrl' => $validated['redirectUrl'] ?? null,
                ],
            ],
        ];

        // Optional fields
        if (!empty($validated['callbackUrl'])) {
            $payload['callbackUrl'] = $validated['callbackUrl'];
        }
        if (!empty($validated['validFor'])) {
            $payload['expireAfter'] = (int) $validated['validFor'];
        }
        if (!empty($validated['merchantUserId'])) {
            $payload['merchantUserId'] = $validated['merchantUserId'];
        }
        if (!empty($validated['note'])) {
            $payload['metaInfo']['udf1'] = $validated['note'];
        }

        // Build merchantUrls only if needed
        $merchantUrls = [
            'redirectUrl' => $validated['redirectUrl'] ?? null,
            'redirectMethod' => $validated['redirectMode'] ?? null,
        ];
        $merchantUrls = array_filter($merchantUrls, fn($v) => !is_null($v));
        if (!empty($merchantUrls)) {
            $payload['paymentFlow']['merchantUrls'] = $merchantUrls;
        }

        $sentRedirect = $merchantUrls['redirectUrl'] ?? null;

        if (!empty($merchantUrls)) {
            $payload['paymentFlow']['merchantUrls'] = $merchantUrls;   // object with keys
            // If you *must* send an empty object instead of omitting:
            // $payload['paymentFlow']['merchantUrls'] = (object) $merchantUrls;
        }

        // Tracing & idempotency
        $requestId = (string) Str::uuid();
        $idempotencyKey = (string) Str::uuid();

        try {
            $url = $pgBase . self::EP_CREATE_ORDER;
            $resp = Http::timeout($this->timeout())
                ->withHeaders([
                    'Authorization' => 'O-Bearer ' . $token,
                    'X-Request-Id' => $requestId,
                    'X-Idempotency-Key' => $idempotencyKey,
                ])
                ->asJson()
                ->post($url, $payload);

            $json = $resp->json();
            $raw = $json ?? $resp->body();

            Log::info('PhonePe create order', [
                'env' => $env,
                'status' => $resp->status(),
                'req' => $payload,
                'resp' => is_array($raw) ? $raw : (string) $raw,
                'x-request-id' => $requestId,
            ]);

            if (!$resp->successful()) {
                return response()->json([
                    'status' => 'error',
                    'env' => $env,
                    'http' => $resp->status(),
                    'body' => $raw,
                ], 502);
            }

            return response()->json([
                'ok' => true,
                'orderId' => $json['orderId'] ?? null,
                'state' => $json['state'] ?? null,
                'expireAt' => $json['expireAt'] ?? 0,
                'token' => $json['token'] ?? null,
                'redirectUrl' => $sentRedirect
                    ?? ($json['paymentUrls']['redirectUrl'] ?? $json['redirectUrl'] ?? null),
                'marchandTransactionId' => $validated['merchantOrderId'] ?? null,
            ]);

        } catch (\Throwable $e) {
            Log::error('PhonePe create order exception', [
                'env' => $env,
                'ex' => $e->getMessage(),
                'x-request-id' => $requestId,
            ]);
            return response()->json([
                'ok' => false,
                'orderId' => null,
                'state' => 'FAILED',
                'expireAt' => 0,
                'token' => null,
                'redirectUrl' => null,
            ]);

        }
    }

    /* =========================================================
     * ORDER STATUS
     * =======================================================*/

    /** GET /api/phonepe/order/{merchantOrderId}/status */
    public function getOrderStatus(Request $request, string $merchantOrderId)
    {
        $env = $this->resolveEnv($request);
        [$clientId, $clientSecret] = $this->creds($env);

        try {
            $token = $this->fetchToken($env, $clientId, $clientSecret);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'env' => $env,
                'message' => 'OAuth failed',
                'error' => $e->getMessage(),
            ], 500);
        }

        try {
            $url = $this->pgBase($env) . str_replace('{merchantOrderId}', urlencode($merchantOrderId), self::EP_ORDER_STATUS);
            $resp = Http::timeout($this->timeout())
                ->withHeaders(['Authorization' => 'O-Bearer ' . $token])
                ->get($url);

            $json = $resp->json();
            $raw = $json ?? $resp->body();

            Log::info('PhonePe order status', [
                'env' => $env,
                'status' => $resp->status(),
                'order' => $merchantOrderId,
                'resp' => is_array($raw) ? $raw : (string) $raw,
                'url' => $url,
            ]);

            if (!$resp->successful()) {
                return response()->json([
                    'success' => false,
                    'env' => $env,
                    'http' => $resp->status(),
                    'body' => $raw,
                ], 502);
            }

            return response()->json([
                'success' => true,
                'env' => $env,
                'merchantOrderId' => $merchantOrderId,
                'data' => $json ?? $raw,
                'state' => $json['state'] ?? null,
                'url' => $url
            ]);

        } catch (\Throwable $e) {
            Log::error('PhonePe order status exception', [
                'env' => $env,
                'ex' => $e->getMessage(),
                'order' => $merchantOrderId
            ]);
            return response()->json([
                'success' => false,
                'env' => $env,
                'message' => 'Exception while fetching order status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
