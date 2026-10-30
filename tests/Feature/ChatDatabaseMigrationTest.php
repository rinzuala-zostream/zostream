<?php

namespace Tests\Feature;

use App\Support\Chat\ChatDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ChatDatabaseMigrationTest extends TestCase
{
    public function test_chat_migration_history_and_tables_are_isolated_from_the_primary_database(): void
    {
        config([
            'database.default' => 'primary_migration_test',
            'chat.primary_connection' => 'primary_migration_test',
            'chat.connection' => 'chat_migration_test',
            'database.connections.primary_migration_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.chat_migration_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        DB::purge('primary_migration_test');
        DB::purge('chat_migration_test');
        try {
            $this->assertSame(0, Artisan::call('chat:migrate', ['--force' => true]));
            $this->assertTrue(ChatDatabase::schema()->hasTable('chat_messages'));
            $this->assertTrue(ChatDatabase::schema()->hasColumn('chat_outbox', 'claimed_until'));
            $this->assertTrue(ChatDatabase::schema()->hasTable('migrations'));
            $this->assertFalse(ChatDatabase::schema()->hasTable('jobs'));
            $this->assertSame([], Schema::getTableListing());
            $this->assertSame('primary_migration_test', DB::getDefaultConnection());
            $this->assertSame(14, ChatDatabase::connection()->table('migrations')->count());
            $this->assertSame(0, Artisan::call('chat:migrate', ['--force' => true]));
            $this->assertSame(14, ChatDatabase::connection()->table('migrations')->count());
        } finally {
            DB::purge('primary_migration_test');
            DB::purge('chat_migration_test');
        }
    }
}
