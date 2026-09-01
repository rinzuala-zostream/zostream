<?php

namespace Tests\Unit;

use Tests\TestCase;

class TrainRecommenderFromBackupTest extends TestCase
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

    public function test_it_rejects_an_unsafe_training_database_name_before_restore(): void
    {
        config()->set('recommender.training_database', 'zo_stream_db');

        $this->artisan('recommender:train-db-backup')
            ->expectsOutputToContain('Unsafe training database name')
            ->assertExitCode(1);
    }

    public function test_it_stops_when_no_backup_matches(): void
    {
        config()->set('recommender.training_database', 'zo_stream_recommender_training');
        config()->set('recommender.backup_pattern', '/tmp/no-zostream-backup-*.sql.gz');

        $this->artisan('recommender:train-db-backup')
            ->expectsOutputToContain('No MySQL backup matches')
            ->assertExitCode(1);
    }

    public function test_it_rejects_a_dump_that_can_switch_databases(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'recommender-test-');
        $backup = $temporary.'.sql.gz';
        rename($temporary, $backup);
        $this->temporaryFiles[] = $backup;
        $handle = gzopen($backup, 'wb');
        gzwrite($handle, "USE `production`;\nCREATE TABLE example (id INT);\n");
        gzclose($handle);

        config()->set('recommender.training_database', 'zo_stream_recommender_training');

        $this->artisan('recommender:train-db-backup', ['--backup' => $backup])
            ->expectsOutputToContain('database-selection statements')
            ->assertExitCode(1);
    }
}
