<?php

namespace App\Support\Analytics;

use Illuminate\Support\Facades\Cache;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Http\HttpClientOptions;
use Throwable;

class AnalyticsCollectionSetting
{
    // Firebase remains authoritative. Direct Firebase edits propagate within 15s.
    private const TTL_SECONDS = 15;

    private function key(): string
    {
        return 'analytics:collection-enabled:v1:'.hash('sha256', (string) config('firebase.database_url'));
    }

    public function enabled(): bool
    {
        try {
            $cache = Cache::store();
            $key = $this->key();

            // A shared lock makes the short TTL safe when many workers hit an
            // expired setting together. Recheck after acquiring it because a
            // previous waiter may already have refreshed the value.
            return $cache->lock($key.':refresh-lock', 10)->block(4, function () use ($cache, $key): bool {
                if ($cache->has($key)) {
                    return $cache->get($key) === true;
                }

                $enabled = $this->readRemote();
                $cache->put($key, $enabled, self::TTL_SECONDS);

                return $enabled;
            }) === true;
        } catch (Throwable $error) {
            report($error);

            return false;
        }
    }

    public function publish(bool $enabled): void
    {
        try {
            Cache::put($this->key(), $enabled, self::TTL_SECONDS);
        } catch (Throwable $error) {
            // Firebase was already saved; do not report the admin update as failed.
            report($error);
        }
    }

    protected function readRemote(): bool
    {
        $url = (string) config('firebase.database_url', '');
        if ($url === '') {
            return false;
        }

        return (new Factory)
            ->withHttpClientOptions(
                HttpClientOptions::default()
                    ->withConnectTimeout(2.0)
                    ->withTimeout(4.0)
            )
            ->withServiceAccount((string) config('firebase.credentials'))
            ->withDatabaseUri($url)
            ->createDatabase()
            ->getReference('config/analytics/enabled')->getValue() === true;
    }
}
