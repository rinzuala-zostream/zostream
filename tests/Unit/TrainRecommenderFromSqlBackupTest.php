<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TrainRecommenderFromSqlBackupTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_it_stops_when_no_sql_backup_matches(): void
    {
        config()->set('recommender.backup_pattern', '/tmp/no-direct-sql-backup-*.sql.gz');

        $this->artisan('recommender:train-sql-backup')
            ->expectsOutputToContain('No MySQL backup matches')
            ->assertExitCode(1);
    }

    public function test_it_removes_extracted_files_when_dump_training_fails(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'sql-backup-test-');
        $backup = $temporary.'.sql.gz';
        rename($temporary, $backup);
        $this->temporaryFiles[] = $backup;
        $handle = gzopen($backup, 'wb');
        gzwrite($handle, "CREATE TABLE `movie` (\n  `id` varchar(255)\n) ENGINE=InnoDB;\n");
        gzclose($handle);

        $root = storage_path('app/recommender-training');
        $before = File::glob($root.'/run-*');

        $this->artisan('recommender:train-sql-backup', ['--backup' => $backup])
            ->expectsOutputToContain('Removed temporary extracted backup files')
            ->assertExitCode(1);

        $this->assertSame($before, File::glob($root.'/run-*'));
    }
}
