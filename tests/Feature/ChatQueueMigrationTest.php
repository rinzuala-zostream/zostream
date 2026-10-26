<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChatQueueMigrationTest extends TestCase
{
    public function test_queue_tables_are_created_and_existing_work_is_preserved(): void
    {
        config(['database.default' => 'queue_testing', 'database.connections.queue_testing' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('queue_testing');
        try {
            $migration = require database_path('migrations/2026_10_26_000005_ensure_chat_delivery_queue_tables.php');
            $migration->up();
            $this->assertTrue(Schema::hasTable('jobs'));
            $this->assertTrue(Schema::hasTable('failed_jobs'));
            DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'existing work', 'attempts' => 0,
                'available_at' => time(), 'created_at' => time()]);
            $migration->up();
            $migration->down();
            $this->assertSame('existing work', DB::table('jobs')->value('payload'));
            $this->assertTrue(Schema::hasColumn('failed_jobs', 'uuid'));
        } finally {
            DB::purge('queue_testing');
        }
    }
}
