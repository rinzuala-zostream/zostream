<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;

class HomeRecommendationService
{
    public function homepage(
        string $userId,
        int $limit,
        string $contentMode = 'adult',
        bool $includeAgeRestricted = false
    ): array {
        $script = (string) config('recommender.script');
        $model = (string) config('recommender.model');

        if (! is_file($script) || ! is_readable($script)) {
            throw new RuntimeException('Recommendation script is unavailable.');
        }

        if (! is_file($model) || ! is_readable($model)) {
            throw new RuntimeException('Recommendation model is unavailable.');
        }

        $modelVersion = (string) filemtime($model);
        $cacheKey = sprintf(
            'recommendations:home:%s:%d:%s:%d:%s',
            hash('sha256', $userId),
            $limit,
            $contentMode,
            (int) $includeAgeRestricted,
            $modelVersion
        );
        $cacheSeconds = max(0, (int) config('recommender.cache_seconds', 300));

        if ($cacheSeconds === 0) {
            return $this->run($userId, $limit, $contentMode, $includeAgeRestricted, $script, $model);
        }

        return Cache::remember(
            $cacheKey,
            now()->addSeconds($cacheSeconds),
            fn (): array => $this->run($userId, $limit, $contentMode, $includeAgeRestricted, $script, $model)
        );
    }

    private function run(
        string $userId,
        int $limit,
        string $contentMode,
        bool $includeAgeRestricted,
        string $script,
        string $model
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
        ];
        if ($includeAgeRestricted) {
            $command[] = '--include-age-restricted';
        }

        $process = new Process($command, base_path());
        $process->setTimeout(max(1.0, (float) config('recommender.timeout_seconds', 30)));
        $process->setIdleTimeout(null);
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
}
