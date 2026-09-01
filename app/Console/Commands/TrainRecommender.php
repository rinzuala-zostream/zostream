<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;

class TrainRecommender extends Command
{
    protected $signature = 'recommender:train
        {--source= : Training source: mysql, csv, or sql-dump}
        {--data-dir= : CSV directory when --source=csv}
        {--dump-file= : Extracted .sql file when --source=sql-dump}
        {--database= : MySQL database override for isolated training}';

    protected $description = 'Train and atomically replace the Zo Stream recommendation model';

    public function handle(): int
    {
        $source = strtolower((string) ($this->option('source') ?: config('recommender.train_source', 'mysql')));
        if (! in_array($source, ['mysql', 'csv', 'sql-dump'], true)) {
            $this->error('Training source must be mysql, csv, or sql-dump.');

            return self::INVALID;
        }

        $timeout = max(60, (int) config('recommender.train_timeout_seconds', 3600));
        $lock = Cache::lock('recommender:training', $timeout + 300);
        if (! $lock->get()) {
            $this->warn('A recommender training process is already running.');

            return self::INVALID;
        }

        try {
            return $this->runTraining($source, $timeout);
        } finally {
            $lock->release();
        }
    }

    private function runTraining(string $source, int $timeout): int
    {
        $script = (string) config('recommender.script');
        $model = (string) config('recommender.model');
        if (! is_file($script) || ! is_readable($script)) {
            throw new RuntimeException('Recommendation script is unavailable.');
        }

        $command = [
            (string) config('recommender.python', 'python3'),
            $script,
            'train',
            '--source',
            $source,
            '--output',
            $model,
        ];

        $environment = null;
        if ($source === 'csv') {
            $command[] = '--data-dir';
            $command[] = (string) ($this->option('data-dir') ?: config('recommender.train_data_dir', base_path()));
        } elseif ($source === 'sql-dump') {
            $dumpFile = (string) $this->option('dump-file');
            if ($dumpFile === '') {
                throw new RuntimeException('--dump-file is required for sql-dump training.');
            }
            $command[] = '--dump-file';
            $command[] = $dumpFile;
        } else {
            $environment = $this->mysqlEnvironment(
                $this->option('database') ? (string) $this->option('database') : null
            );
        }

        $this->info("Training recommender from {$source}...");
        $process = new Process($command, base_path(), $environment);
        $process->setTimeout($timeout);
        $process->setIdleTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error(trim($process->getErrorOutput()) ?: 'Recommendation training failed.');

            return self::FAILURE;
        }

        $payload = json_decode($process->getOutput(), true);
        if (! is_array($payload) || ! isset($payload['catalog_items'])) {
            $this->error('Training completed but returned an invalid result.');

            return self::FAILURE;
        }

        clearstatcache(true, $model);
        $this->info('Recommendation model trained successfully.');
        $this->table(['Metric', 'Value'], collect($payload)->map(
            fn ($value, $key) => [$key, is_scalar($value) ? (string) $value : json_encode($value)]
        )->values()->all());

        return self::SUCCESS;
    }

    private function mysqlEnvironment(?string $databaseOverride = null): array
    {
        $connectionName = (string) config('database.default');
        $database = (array) config("database.connections.{$connectionName}", []);
        if (! in_array($database['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('The default Laravel database must be MySQL or MariaDB.');
        }

        $training = (array) config('recommender.train_mysql', []);
        $database = array_replace($database, array_filter(
            $training,
            fn ($value) => $value !== null && $value !== ''
        ));
        if ($databaseOverride !== null) {
            if (! preg_match('/\A[A-Za-z0-9_]+\z/', $databaseOverride)) {
                throw new RuntimeException('The MySQL database override is invalid.');
            }
            $database['database'] = $databaseOverride;
            // A production DB_URL would otherwise override the isolated database
            // name when Python parses the connection settings.
            $database['url'] = null;
        }

        return array_filter([
            'RECOMMENDER_DB_URL' => $database['url'] ?? null,
            'RECOMMENDER_DB_HOST' => (string) ($database['host'] ?? '127.0.0.1'),
            'RECOMMENDER_DB_PORT' => (string) ($database['port'] ?? '3306'),
            'RECOMMENDER_DB_NAME' => (string) ($database['database'] ?? ''),
            'RECOMMENDER_DB_USER' => (string) ($database['username'] ?? ''),
            'RECOMMENDER_DB_PASSWORD' => (string) ($database['password'] ?? ''),
            'RECOMMENDER_DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
            'RECOMMENDER_DB_CHARSET' => (string) ($database['charset'] ?? 'utf8mb4'),
            'RECOMMENDER_DB_PREFIX' => (string) ($database['prefix'] ?? ''),
        ], fn ($value) => $value !== null);
    }
}
