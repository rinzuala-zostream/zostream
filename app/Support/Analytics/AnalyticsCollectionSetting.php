<?php

namespace App\Support\Analytics;

use Illuminate\Support\Facades\Cache;
use Kreait\Firebase\Factory;
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
            return Cache::remember($this->key(), self::TTL_SECONDS, fn (): bool => $this->readRemote()) === true;
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
            ->withServiceAccount((string) config('firebase.credentials'))
            ->withDatabaseUri($url)
            ->createDatabase()
            ->getReference('config/analytics/enabled')->getValue() === true;
    }
}
