<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class HomeRecommendationService
{
    public function __construct(
        private readonly LiveHomeSectionService $liveSections,
    ) {}

    public function homepage(
        string $userId,
        int $limit,
        string $contentMode = 'adult',
        bool $includeAgeRestricted = false,
        ?array $requestedSections = null
    ): array {
        $freshSeconds = max(0, (int) config('recommender.response_cache_fresh_seconds', 30));
        $staleSeconds = max($freshSeconds, (int) config('recommender.response_cache_stale_seconds', 300));

        if ($freshSeconds === 0 || $staleSeconds === 0) {
            return $this->buildHomepage(
                $userId,
                $limit,
                $contentMode,
                $includeAgeRestricted,
                $requestedSections
            );
        }

        $sections = $requestedSections;
        if ($sections !== null) {
            $sections = array_values(array_unique($sections));
            sort($sections);
        }
        $model = (string) config('recommender.model');
        $modelVersion = is_file($model) ? (string) filemtime($model) : 'unavailable';
        $cacheKey = sprintf(
            'recommendations:home-response:%s:%d:%s:%d:%s:%s',
            hash('sha256', $userId),
            $limit,
            $contentMode,
            (int) $includeAgeRestricted,
            $modelVersion,
            hash('sha256', json_encode($sections, JSON_THROW_ON_ERROR))
        );

        try {
            return Cache::flexible(
                $cacheKey,
                [$freshSeconds, $staleSeconds],
                fn (): array => $this->buildHomepage(
                    $userId,
                    $limit,
                    $contentMode,
                    $includeAgeRestricted,
                    $requestedSections
                ),
                ['seconds' => 30]
            );
        } catch (Throwable $exception) {
            // Cache availability must not become a homepage availability
            // dependency. Continue through the uncached path instead.
            Log::warning('Home recommendation response cache failed.', [
                'exception' => $exception,
            ]);

            return $this->buildHomepage(
                $userId,
                $limit,
                $contentMode,
                $includeAgeRestricted,
                $requestedSections
            );
        }
    }

    private function buildHomepage(
        string $userId,
        int $limit,
        string $contentMode,
        bool $includeAgeRestricted,
        ?array $requestedSections
    ): array {
        $script = (string) config('recommender.script');
        $model = (string) config('recommender.model');

        $needsAi = $requestedSections === null || array_intersect($requestedSections, [
            'because_you_watched',
            'top_picks_for_you',
            'similar_movies',
        ]) !== [];
        $live = $this->liveSections->snapshot(
            $userId,
            $limit,
            $contentMode,
            $includeAgeRestricted,
            $requestedSections,
            $needsAi
        );

        if (! $needsAi) {
            return $this->withPpvSeasonMetadata($this->liveOnlyPayload($live));
        }

        if (! is_file($script) || ! is_readable($script) || ! is_file($model) || ! is_readable($model)) {
            Log::warning('Recommendation model is unavailable to the web worker; serving live sections only.', [
                'script_readable' => is_file($script) && is_readable($script),
                'model_readable' => is_file($model) && is_readable($model),
            ]);

            return $this->withPpvSeasonMetadata($this->liveOnlyPayload($live));
        }

        $modelVersion = (string) filemtime($model);
        $cacheKey = sprintf(
            'recommendations:home:%s:%d:%s:%d:%s:%s',
            hash('sha256', $userId),
            $limit,
            $contentMode,
            (int) $includeAgeRestricted,
            $modelVersion,
            $live['version']
        );
        $cacheSeconds = max(0, (int) config('recommender.cache_seconds', 300));

        try {
            if ($cacheSeconds === 0) {
                $payload = $this->run($userId, $limit, $contentMode, $includeAgeRestricted, $script, $model, $live['signals']);
            } else {
                $payload = Cache::remember(
                    $cacheKey,
                    now()->addSeconds($cacheSeconds),
                    fn (): array => $this->run($userId, $limit, $contentMode, $includeAgeRestricted, $script, $model, $live['signals'])
                );
            }

            $payload = $this->liveSections->filterAiSections(
                $payload,
                $contentMode,
                $includeAgeRestricted
            );
            foreach ($live['sections'] as $section => $items) {
                $payload[$section] = $items;
            }

            return $this->withPpvSeasonMetadata($payload);
        } catch (Throwable $exception) {
            Log::warning('Recommendation model execution failed; serving live sections only.', [
                'exception' => $exception,
            ]);

            return $this->withPpvSeasonMetadata($this->liveOnlyPayload($live));
        }
    }

    /** Add season and PPV episode summaries to movie cards in the home payload. */
    private function withPpvSeasonMetadata(array $payload): array
    {
        $movieIds = [];
        $collect = function (mixed $value) use (&$collect, &$movieIds): void {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['id']) && ! isset($value['episode_number']) && ! isset($value['season_number'])) {
                $movieIds[] = (string) $value['id'];
            }
            foreach ($value as $child) {
                if (is_array($child)) {
                    $collect($child);
                }
            }
        };
        $collect($payload);
        $movieIds = array_values(array_unique($movieIds));
        if ($movieIds === []) {
            return $payload;
        }

        $movies = DB::table('movie')->whereIn('id', $movieIds)->pluck('num', 'id');
        if ($movies->isEmpty()) {
            return $payload;
        }

        $seasonRows = DB::table('seasons')
            ->leftJoin('episodes', 'episodes.season_id', '=', 'seasons.id')
            ->whereIn('seasons.movie_id', $movies->values()->all())
            ->groupBy('seasons.id', 'seasons.movie_id', 'seasons.season_number', 'seasons.title', 'seasons.isPayPerView')
            ->select(
                'seasons.id',
                'seasons.movie_id',
                'seasons.season_number',
                'seasons.title',
                'seasons.isPayPerView',
                DB::raw('SUM(CASE WHEN episodes.isPayPerView = 1 THEN 1 ELSE 0 END) as ppv_episode_count')
            )
            ->havingRaw('MAX(CASE WHEN seasons.isPayPerView = 1 OR episodes.isPayPerView = 1 THEN 1 ELSE 0 END) = 1')
            ->orderBy('seasons.movie_id')
            ->orderBy('seasons.season_number')
            ->get();

        $seasonsByMovie = [];
        foreach ($seasonRows as $season) {
            $seasonsByMovie[(string) $season->movie_id][] = [
                'season_id' => (string) $season->id,
                'season_number' => (int) $season->season_number,
                'title' => (string) ($season->title ?? ''),
                'isPayPerView' => (bool) $season->isPayPerView,
                'ppv_episode_count' => (int) $season->ppv_episode_count,
            ];
        }

        $enrich = function (mixed &$value) use (&$enrich, $movies, $seasonsByMovie): void {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['id']) && ! isset($value['episode_number']) && ! isset($value['season_number'])) {
                $movieNumber = $movies->get((string) $value['id']);
                if ($movieNumber !== null) {
                    $value['ppv_seasons'] = $seasonsByMovie[(string) $movieNumber] ?? [];
                }
            }
            foreach ($value as &$child) {
                if (is_array($child)) {
                    $enrich($child);
                }
            }
            unset($child);
        };
        $enrich($payload);

        return $payload;
    }

    private function run(
        string $userId,
        int $limit,
        string $contentMode,
        bool $includeAgeRestricted,
        string $script,
        string $model,
        array $signals
    ): array {
        $command = [
            (string) config('recommender.python', 'python3'),
            $script,
            'homepage',
            '--model',
            $model,
            '--user-id',
            $userId,
            '--limit',
            (string) $limit,
            '--mode',
            $contentMode,
            '--live-data-stdin',
        ];
        if ($includeAgeRestricted) {
            $command[] = '--include-age-restricted';
        }

        $process = new Process($command, base_path());
        $process->setTimeout(max(1.0, (float) config('recommender.timeout_seconds', 30)));
        $process->setIdleTimeout(null);
        $process->setInput(json_encode($signals, JSON_THROW_ON_ERROR));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Recommendation process failed with exit code %s.',
                (string) $process->getExitCode()
            ));
        }

        try {
            $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Recommendation process returned invalid JSON.', previous: $exception);
        }

        if (! is_array($payload) || ! isset($payload['history_size'])) {
            throw new RuntimeException('Recommendation process returned an invalid payload.');
        }

        return $payload;
    }

    private function liveOnlyPayload(array $live): array
    {
        return array_merge([
            'history_size' => count($live['signals']['watch_position'] ?? []),
            'because_you_watched' => [],
            'top_picks_for_you' => [],
            'similar_movies' => [],
        ], $live['sections']);
    }
}
