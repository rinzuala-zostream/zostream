<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
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
            return $this->liveOnlyPayload($live);
        }

        if (! is_file($script) || ! is_readable($script) || ! is_file($model) || ! is_readable($model)) {
            Log::warning('Recommendation model is unavailable to the web worker; serving live sections only.', [
                'script_readable' => is_file($script) && is_readable($script),
                'model_readable' => is_file($model) && is_readable($model),
            ]);

            return $this->liveOnlyPayload($live);
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

            return $payload;
        } catch (Throwable $exception) {
            Log::warning('Recommendation model execution failed; serving live sections only.', [
                'exception' => $exception,
            ]);

            return $this->liveOnlyPayload($live);
        }
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
