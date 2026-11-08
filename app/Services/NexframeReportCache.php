<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class NexframeReportCache
{
    public function remember(string $key, Closure $compute): array
    {
        // A non-database store keeps dashboard reads off the application DB.
        $cache = Cache::store(config('services.nexframe_dashboard.business_cache_store', 'file'));
        $entry = $cache->get($key);
        if ($this->fresh($entry)) {
            return $this->result($entry);
        }

        $lock = $cache->lock($key.':refresh', 300);
        if (! $lock->get()) {
            if ($entry) {
                return $this->result($entry, true);
            }
            throw new HttpException(503, 'Business analytics are being prepared. Please retry shortly.', null, ['Retry-After' => '5']);
        }

        try {
            // Another worker may have populated this while we acquired the lock.
            $latest = $cache->get($key);
            if ($this->fresh($latest)) {
                return $this->result($latest);
            }
            try {
                $report = $compute();
            } catch (Throwable $error) {
                if ($entry) {
                    return $this->result($entry, true);
                }
                throw $error;
            }

            $healthy = ! in_array(false, $report['source_status'] ?? [], true);
            $ttl = $healthy ? 300 : 60;
            $entry = ['report' => $report, 'as_of' => now()->toIso8601String(), 'fresh_until' => now()->timestamp + $ttl, 'ttl' => $ttl];
            $cache->put($key, $entry, 1800);

            return $this->result($entry);
        } finally {
            $lock->release();
        }
    }

    private function fresh(?array $entry): bool
    {
        return $entry && $entry['fresh_until'] > now()->timestamp;
    }

    private function result(array $entry, bool $stale = false): array
    {
        return [...$entry['report'], 'business_snapshot' => [
            'as_of' => $entry['as_of'],
            'refresh_interval_seconds' => $entry['ttl'],
            'stale' => $stale,
        ]];
    }
}
