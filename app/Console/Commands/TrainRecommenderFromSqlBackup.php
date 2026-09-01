<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class TrainRecommenderFromSqlBackup extends Command
{
    protected $signature = 'recommender:train-sql-backup
        {--backup= : Specific .sql.gz backup; latest configured backup is used by default}
        {--keep-temporary-files : Keep extracted SQL for diagnosis}';

    protected $description = 'Extract the latest MySQL backup, train directly from SQL rows, then clean up';

    public function handle(): int
    {
        $timeout = max(60, (int) config('recommender.train_timeout_seconds', 3600));
        $lock = Cache::lock('recommender:sql-backup-training-pipeline', $timeout + 2400);
        if (! $lock->get()) {
            $this->warn('An SQL backup training pipeline is already running.');

            return self::INVALID;
        }
        $temporaryDirectory = null;
        $result = self::FAILURE;
        try {
            $backup = $this->resolveBackup();
            $this->validateBackup($backup);
            $temporaryDirectory = $this->makeTemporaryDirectory();
            $dumpFile = $temporaryDirectory.'/backup.sql';
            $this->extractBackup($backup, $dumpFile);
            $result = $this->train($dumpFile);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
        } finally {
            if ($temporaryDirectory && ! $this->option('keep-temporary-files')) {
                if ($this->safeToDelete($temporaryDirectory)) {
                    File::deleteDirectory($temporaryDirectory);
                    $this->info('Removed temporary extracted backup files.');
                } else {
                    $this->error('Temporary path safety check failed; files were not removed.');
                    $result = self::FAILURE;
                }
            }
            $lock->release();
        }

        return $result;
    }

    private function resolveBackup(): string
    {
        if ($this->option('backup')) {
            $path = realpath((string) $this->option('backup'));

            return $path !== false ? $path : (string) $this->option('backup');
        }
        $files = array_values(array_filter(
            glob((string) config('recommender.backup_pattern')) ?: [],
            'is_file'
        ));
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
    }

    private function makeTemporaryDirectory(): string
    {
        $root = storage_path('app/recommender-training');
        File::ensureDirectoryExists($root, 0700);
        $directory = $root.'/run-'.date('Ymd-His').'-'.bin2hex(random_bytes(6));
        File::makeDirectory($directory, 0700);

        return $directory;
    }

    private function extractBackup(string $backup, string $dumpFile): void
    {
        $this->info("Extracting backup into protected temporary storage: {$backup}");
        $input = gzopen($backup, 'rb');
        $output = fopen($dumpFile, 'wb');
        if ($input === false || $output === false) {
            throw new RuntimeException('Could not open backup extraction streams.');
        }
        try {
            while (! gzeof($input)) {
                $chunk = gzread($input, 1024 * 1024);
                if ($chunk === false || fwrite($output, $chunk) === false) {
                    throw new RuntimeException('Backup extraction failed.');
                }
            }
        } finally {
            gzclose($input);
            fclose($output);
        }
        chmod($dumpFile, 0600);
    }

    private function train(string $dumpFile): int
    {
        $this->info('Backup extraction completed; training directly from SQL dump...');
        $process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'recommender:train',
            '--source=sql-dump',
            "--dump-file={$dumpFile}",
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

    private function safeToDelete(string $directory): bool
    {
        $root = realpath(storage_path('app/recommender-training'));
        $target = realpath($directory);

        return $root !== false
            && $target !== false
            && str_starts_with($target, $root.DIRECTORY_SEPARATOR.'run-');
    }
}
