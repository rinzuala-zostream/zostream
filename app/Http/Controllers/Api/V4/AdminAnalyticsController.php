<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Support\Api\V4Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;

class AdminAnalyticsController extends Controller
{
    private const CONFIG_PATH = 'config/analytics';

    public function showConfig(): JsonResponse
    {
        try {
            $value = $this->database()->getReference(self::CONFIG_PATH)->getValue();

            return V4Response::success([
                'path' => '/'.self::CONFIG_PATH.'/enabled',
                'enabled' => ($value['enabled'] ?? false) === true,
                'updated_at' => is_string($value['updated_at'] ?? null) ? $value['updated_at'] : null,
            ]);
        } catch (\Throwable $error) {
            Log::error('Could not read analytics collection setting.', ['exception' => $error]);

            return V4Response::error(
                'ANALYTICS_CONFIG_UNAVAILABLE',
                'Analytics collection setting is unavailable.',
                503
            );
        }
    }

    public function updateConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        try {
            $updatedAt = now('UTC')->toIso8601String();
            $this->database()->getReference(self::CONFIG_PATH)->update([
                'enabled' => (bool) $data['enabled'],
                'updated_at' => $updatedAt,
            ]);

            return V4Response::success([
                'path' => '/'.self::CONFIG_PATH.'/enabled',
                'enabled' => (bool) $data['enabled'],
                'updated_at' => $updatedAt,
            ], $data['enabled'] ? 'Analytics collection enabled.' : 'Analytics collection disabled.');
        } catch (\Throwable $error) {
            Log::error('Could not update analytics collection setting.', ['exception' => $error]);

            return V4Response::error(
                'ANALYTICS_CONFIG_UNAVAILABLE',
                'Analytics collection setting could not be saved.',
                503
            );
        }
    }

    private function database()
    {
        $url = (string) config('firebase.database_url', '');
        if ($url === '') {
            throw new \RuntimeException('Firebase Realtime Database URL is not configured.');
        }

        return (new Factory)
            ->withServiceAccount((string) config('firebase.credentials'))
            ->withDatabaseUri($url)
            ->createDatabase();
    }
}
