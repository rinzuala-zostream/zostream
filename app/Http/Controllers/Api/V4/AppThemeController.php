<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Services\Themes\ThemeResolver;
use App\Services\Themes\ThemeSchema;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;

class AppThemeController extends Controller
{
    public function show(Request $request, ThemeResolver $resolver)
    {
        $platform = strtolower((string) $request->header('X-Platform', $request->header('X-Client-Platform', 'unknown')));
        if (! in_array($platform, ThemeSchema::PLATFORMS, true)) {
            $platform = 'unknown';
        }
        $payload = $resolver->resolve($platform);
        $etag = 'W/"'.hash('sha256', json_encode($payload)).'"';
        $response = V4Response::success($payload, meta: ['server_time' => now('UTC')->toIso8601String()]);
        $response->headers->set('ETag', $etag);
        // Revalidate on every request: emergency restore must not wait for a CDN TTL.
        $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate');
        $response->headers->set('Vary', 'X-Platform, X-Client-Platform');
        $response->headers->set('X-Server-Time', now('UTC')->toIso8601String());
        $response->isNotModified($request);

        return $response;
    }
}
