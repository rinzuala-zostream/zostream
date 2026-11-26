<?php

namespace App\Support\Catalog;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class PublicMovieCatalogCache
{
    private const TTL_SECONDS = 30;

    /**
     * Cache serialized public catalog arrays by catalog revision and request
     * dimensions. A cache/lock failure falls back to the normal DB query.
     */
    public function remember(string $scope, array $dimensions, Closure $build): array
    {
        try {
            $revision = MovieCatalogRevision::current();
            $cache = Cache::store();
            ksort($dimensions);
            $identity = hash('sha256', json_encode([
                'scope' => $scope,
                'revision' => $revision,
                'dimensions' => $dimensions,
            ], JSON_THROW_ON_ERROR));
            $key = 'public-movie-catalog:v1:'.$identity;

            if ($cache->has($key)) {
                $cached = $cache->get($key);
                if (is_array($cached)) {
                    return $cached;
                }
            }
        } catch (Throwable $error) {
            report($error);

            return $build();
        }

        $built = false;
        $builtValue = [];
        $buildError = null;

        try {
            return $cache->lock($key.':lock', 60)->block(5, function () use (
                $cache,
                $key,
                $build,
                &$built,
                &$builtValue,
                &$buildError,
            ): array {
                if ($cache->has($key)) {
                    $cached = $cache->get($key);
                    if (is_array($cached)) {
                        return $cached;
                    }
                }

                try {
                    $builtValue = $build();
                    $built = true;
                } catch (Throwable $error) {
                    $buildError = $error;

                    throw $error;
                }

                $cache->put($key, $builtValue, self::TTL_SECONDS);

                return $builtValue;
            });
        } catch (Throwable $error) {
            if ($buildError !== null) {
                throw $buildError;
            }

            report($error);

            return $built ? $builtValue : $build();
        }
    }

    /**
     * Include every input that can partition these legacy home/list results,
     * while representing user identity only by the public Mizo visibility
     * class. User-specific watch history is never part of this cache.
     */
    public function dimensions(
        string $scope,
        Request $request,
        bool $kidsMode,
        bool $mizoOnly,
        bool $includeAgeRestricted,
        bool $enabledOnly,
    ): array {
        return [
            'scope' => $scope,
            'id' => (string) $request->query('id', ''),
            'range' => (string) $request->query('range', ''),
            'category' => strtolower((string) $request->query('category', '')),
            'category_type' => strtolower((string) $request->query('category_type', '')),
            'kids_mode' => $kidsMode,
            'mizo_only' => $mizoOnly,
            'include_age_restricted' => $includeAgeRestricted,
            'enabled_only' => $enabledOnly,
            'platform_query' => strtolower((string) $request->query('platform', '')),
            'platform_header' => strtolower((string) $request->header('X-Platform', '')),
            'mode_header' => strtolower((string) $request->header('X-Mode', '')),
        ];
    }
}
