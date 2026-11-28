<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApiClientContext
{
    private const PLATFORMS = [
        'android',
        'android-tv',
        'ios',
        'web',
        'webos',
        'tizen',
        'windows',
        'macos',
        'admin',
        'unknown',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request);
        $platform = strtolower(trim((string) $request->header('X-Client-Platform', 'unknown')));

        if (! in_array($platform, self::PLATFORMS, true)) {
            $platform = 'unknown';
        }

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('client_context', [
            'platform' => $platform,
            'version' => trim((string) $request->header('X-Client-Version', 'unknown')),
            'device_type' => trim((string) $request->header('X-Device-Type', 'unknown')),
        ]);
        preg_match('#^api/v([0-9]+)(?:/|$)#', $request->path(), $versionMatch);
        $apiVersion = $versionMatch[1] ?? '4';
        $request->attributes->set('api_version', $apiVersion);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-API-Version', $apiVersion);

        return $response;
    }

    private function requestId(Request $request): string
    {
        $provided = trim((string) $request->header('X-Request-ID', ''));

        if ($provided !== '' && preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $provided)) {
            return $provided;
        }

        return (string) Str::uuid();
    }
}
