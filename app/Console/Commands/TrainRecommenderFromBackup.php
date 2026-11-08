<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class TrainRecommenderFromBackup extends Command
{
    protected $signature = 'recommender:train-db-backup
        {--backup= : Specific .sql.gz backup; latest configured backup is used by default}
        {--keep-training-database : Keep the isolated database for diagnosis}';

    protected $description = 'Legacy: restore a backup into an isolated MySQL database and train';

    public function handle(): int
    {
        $timeout = max(60, (int) config('recommender.train_timeout_seconds', 3600));
        $lock = Cache::lock('recommender:backup-training-pipeline', $timeout + 2400);
        if (! $lock->get()) {
            $this->warn('A backup training pipeline is already running.');

            return self::INVALID;
        }

        $trainingDatabase = (string) config('recommender.training_database');
        $created = false;
        $result = self::FAILURE;

        try {
            $this->assertSafeTrainingDatabase($trainingDatabase);
            $backup = $this->resolveBackup();
            $this->validateBackup($backup);
            $this->assertNotProductionDatabase($trainingDatabase);

            $this->info("Using backup: {$backup}");
            $this->recreateDatabase($trainingDatabase);
            $created = true;
            $this->restoreBackup($backup, $trainingDatabase);
            $result = $this->train($trainingDatabase);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $result = self::FAILURE;
        } finally {
            if ($created && ! $this->option('keep-training-database')) {
                try {
                    $this->dropDatabase($trainingDatabase);
                    $this->info("Removed isolated training database: {$trainingDatabase}");
                } catch (Throwable $exception) {
                    $this->error("Could not remove isolated training database: {$exception->getMessage()}");
                    $result = self::FAILURE;
                }
            }
            $lock->release();
        }

        return $result;
    }

    private function resolveBackup(): string
    {
        $requested = $this->option('backup');
        if ($requested) {
            $path = realpath((string) $requested);

            return $path !== false ? $path : (string) $requested;
        }

        $matches = glob((string) config('recommender.backup_pattern')) ?: [];
        $files = array_values(array_filter($matches, 'is_file'));
        usort($files, fn (string $left, string $right) => filemtime($right) <=> filemtime($left));
        if ($files === []) {
            throw new RuntimeException('No MySQL backup matches the configured backup pattern.');
        }

        return (string) realpath($files[0]);
    }

    private function validateBackup(string $backup): void
    {
        if (! is_file($backup) || ! is_readable($backup) || ! str_ends_with($backup, '.sql.gz')) {
            throw new RuntimeException('The selected MySQL .sql.gz backup is unavailable.');
        }

        $maximumAge = max(1, (int) config('recommender.backup_max_age_hours', 30)) * 3600;
        if (time() - (int) filemtime($backup) > $maximumAge) {
            throw new RuntimeException('The latest MySQL backup is too old; training was not started.');
        }

        $process = new Process([(string) config('recommender.gzip_binary', 'gzip'), '-t', $backup]);
        $process->setTimeout(300);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('The selected MySQL backup failed its gzip integrity check.');
        }

        $this->assertDumpDoesNotSelectDatabase($backup);
    }

    private function assertDumpDoesNotSelectDatabase(string $backup): void
    {
        $handle = gzopen($backup, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The selected MySQL backup could not be inspected.');
        }
        try {
            while (($line = gzgets($handle)) !== false) {
                if (preg_match('/\A\s*(?:USE|CREATE\s+DATABASE|DROP\s+DATABASE)\b/i', $line)) {
                    throw new RuntimeException(
                        'Backup contains database-selection statements; refusing isolated restore.'
                    );
                }
            }
        } finally {
            gzclose($handle);
        }
    }

    private function assertSafeTrainingDatabase(string $database): void
    {
        if (! preg_match('/\Azo_stream_recommender_training(?:_[A-Za-z0-9]+)?\z/', $database)) {
            throw new RuntimeException('Unsafe training database name; refusing restore or cleanup.');
        }
    }

    private function assertNotProductionDatabase(string $trainingDatabase): void
    {
        $defaultConnection = (string) config('database.default');
        $productionDatabase = (string) config("database.connections.{$defaultConnection}.database");
        if ($trainingDatabase === $productionDatabase) {
            throw new RuntimeException('Training database matches the production database; refusing to continue.');
        }
    }

    private function recreateDatabase(string $database): void
    {
        $this->info("Creating isolated training database: {$database}");
        $this->runMysqlControl("DROP DATABASE IF EXISTS `{$database}`");
        $this->runMysqlControl(
            "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    private function dropDatabase(string $database): void
    {
        $this->runMysqlControl("DROP DATABASE IF EXISTS `{$database}`");
    }

    private function runMysqlControl(string $sql): void
    {
        $process = new Process([
            ...$this->mysqlCommand(),
            '--execute',
            $sql,
        ], base_path(), $this->mysqlPasswordEnvironment());
        $process->setTimeout(120);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'MySQL database preparation failed.');
        }
    }

    private function restoreBackup(string $backup, string $database): void
    {
        $this->info("Restoring backup into {$database}...");
        $mysql = (array) config('recommender.train_mysql', []);
        $environment = array_merge($this->mysqlPasswordEnvironment(), [
            'BACKUP_FILE' => $backup,
            'GZIP_BIN' => (string) config('recommender.gzip_binary', 'gzip'),
            'MYSQL_BIN' => (string) config('recommender.mysql_binary', 'mysql'),
            'DB_HOST' => (string) ($mysql['host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($mysql['port'] ?? '3306'),
            'DB_USER' => (string) ($mysql['username'] ?? ''),
            'DB_SOCKET' => (string) ($mysql['unix_socket'] ?? ''),
            'DB_CHARSET' => (string) ($mysql['charset'] ?? 'utf8mb4'),
            'TRAIN_DB' => $database,
        ]);
        $connection = $environment['DB_SOCKET'] !== ''
            ? '--socket="$DB_SOCKET"'
            : '--host="$DB_HOST" --port="$DB_PORT"';
        $shell = '"$GZIP_BIN" -dc -- "$BACKUP_FILE" | "$MYSQL_BIN" '
            .$connection
            .' --user="$DB_USER" --default-character-set="$DB_CHARSET" "$TRAIN_DB"';
        $process = new Process(['/bin/bash', '-o', 'pipefail', '-c', $shell], base_path(), $environment);
        $process->setTimeout(max(60, (int) config('recommender.backup_restore_timeout_seconds', 1800)));
        $process->setIdleTimeout(null);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'MySQL backup restore failed.');
        }
    }

    private function train(string $database): int
    {
        $this->info('Backup restore completed; starting model training...');
        $process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'recommender:train',
            '--source=mysql',
            "--database={$database}",
            '--no-interaction',
        ], base_path());
        $process->setTimeout(max(60, (int) config('recommender.train_timeout_seconds', 3600)));
        $process->setIdleTimeout(null);
        $process->run();
        $this->output->write($process->getOutput());
        if (! $process->isSuccessful()) {
            $this->error(trim($process->getErrorOutput()) ?: 'Model training failed.');
        }

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    private function mysqlCommand(): array
    {
        $mysql = (array) config('recommender.train_mysql', []);
        $command = [(string) config('recommender.mysql_binary', 'mysql')];
        if (! empty($mysql['unix_socket'])) {
            $command[] = '--socket='.(string) $mysql['unix_socket'];
        } else {
            $command[] = '--host='.(string) ($mysql['host'] ?? '127.0.0.1');
            $command[] = '--port='.(string) ($mysql['port'] ?? '3306');
        }
        $command[] = '--user='.(string) ($mysql['username'] ?? '');
        $command[] = '--default-character-set='.(string) ($mysql['charset'] ?? 'utf8mb4');

        return $command;
    }

    private function mysqlPasswordEnvironment(): array
    {
        return ['MYSQL_PWD' => (string) config('recommender.train_mysql.password', '')];
    }
}
