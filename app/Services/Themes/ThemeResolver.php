<?php

namespace App\Services\Themes;

use App\Models\AppThemeOverride;
use App\Models\AppThemeVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ThemeResolver
{
    public function __construct(private ThemeSchema $schema) {}

    public function resolve(string $platform, ?CarbonImmutable $clock = null): array
    {
        $now = $clock ?? CarbonImmutable::now('UTC');
        try {
            $generation = DB::table('app_theme_controls')->where('id', 1)->value('revision');
            $cacheKey = 'app-themes:v2:'.$generation.':'.$platform;
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && ($cached['valid_until'] === null || CarbonImmutable::parse($cached['valid_until'])->greaterThan($now))) {
                return $cached;
            }
            $payload = $this->resolveScheduled($platform, $now);
            $ttl = $payload['next_refresh_at'] ? min(60, (int) $now->diffInSeconds(CarbonImmutable::parse($payload['next_refresh_at']), false)) : 60;
            if ($ttl > 0) {
                Cache::put($cacheKey, $payload, $ttl);
            }

            return $payload;
        } catch (\Throwable $exception) {
            Log::warning('Seasonal theme fallback', ['platform' => $platform, 'exception' => $exception]);
            $fallback = $this->schema->payload('default', 1, $this->schema->defaults(), $platform);

            return array_merge($fallback, ['platform' => $platform, 'valid_until' => null, 'next_refresh_at' => null, 'poll_interval_seconds' => 60, 'fallback' => $fallback]);
        }
    }

    private function resolveScheduled(string $platform, CarbonImmutable $now): array
    {
        $fallback = $this->schema->payload('default', 1, $this->schema->defaults(), $platform);
        $override = AppThemeOverride::with('version.theme')->whereNull('cancelled_at')
            ->where('starts_at', '<=', $now)->where('expires_at', '>', $now)->orderByDesc('id')->first();
        $versions = AppThemeVersion::with(['theme', 'schedules'])
            ->whereIn('id', \App\Models\AppTheme::whereNotNull('published_version_id')->select('published_version_id'))->get();
        $eligible = [];
        $boundaries = [];
        foreach ($versions as $version) {
            if (! in_array($platform, $version->document['platforms'], true)) {
                continue;
            }
            foreach ($version->schedules as $schedule) {
                foreach ([$schedule->start_at, $schedule->end_at] as $boundary) {
                    if ($boundary->greaterThan($now)) {
                        $boundaries[] = $boundary;
                    }
                }
                if ($schedule->start_at->lessThanOrEqualTo($now) && $schedule->end_at->greaterThan($now)) {
                    $eligible[$version->id] = $version;
                }
            }
        }
        usort($boundaries, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());
        $next = $boundaries[0] ?? null;
        $selected = null;
        if ($override) {
            // A manual Default is global; unsupported platforms also fall back safely.
            $selected = $override->version && in_array($platform, $override->version->document['platforms'], true) ? $override->version : null;
            $next = $override->expires_at;
        } else {
            $eligible = array_values($eligible);
            usort($eligible, fn ($a, $b) => ($b->document['priority'] <=> $a->document['priority'])
                ?: ($b->published_at->getTimestamp() <=> $a->published_at->getTimestamp()) ?: ($b->id <=> $a->id));
            $selected = $eligible[0] ?? null;
        }
        $payload = $selected ? $this->schema->payload($selected->theme->key, $selected->number, $this->schema->validate($selected->document, $selected->theme_id), $platform) : $fallback;

        return array_merge($payload, [
            'platform' => $platform,
            'valid_until' => $next?->toIso8601String(),
            'next_refresh_at' => $next?->toIso8601String(),
            'poll_interval_seconds' => 60,
            'fallback' => $fallback,
        ]);
    }
}
