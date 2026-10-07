<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrailerMigrationTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = (string) config('database.default');
        config([
            'database.default' => 'trailer_migration_testing',
            'database.connections.trailer_migration_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('trailer_migration_testing');
        DB::reconnect('trailer_migration_testing');

        Schema::create('movie', function (Blueprint $table) {
            $table->increments('num');
            $table->string('id')->unique();
            $table->string('title');
            $table->text('trailer')->nullable();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('trailer_migration_testing');
        config(['database.default' => $this->originalConnection]);

        parent::tearDown();
    }

    public function test_migration_moves_movie_trailers_to_the_related_table_and_rolls_back_safely(): void
    {
        DB::table('movie')->insert([
            ['id' => 'with-trailer', 'title' => 'With Trailer', 'trailer' => ' https://example.com/trailer.m3u8 '],
            ['id' => 'without-trailer', 'title' => 'Without Trailer', 'trailer' => null],
        ]);

        // Simulate the empty table left by MySQL when CREATE TABLE succeeds
        // but its following foreign-key ALTER fails.
        Schema::create('trailers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('movie_id')->unique();
            $table->text('url');
            $table->timestamps();
        });

        $migration = require database_path('migrations/2026_10_07_000001_create_trailers_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('trailers'));
        $this->assertFalse(Schema::hasColumn('movie', 'trailer'));
        $this->assertDatabaseCount('trailers', 1);
        $this->assertDatabaseHas('trailers', [
            'movie_id' => 1,
            'url' => 'https://example.com/trailer.m3u8',
        ]);

        $migration->down();

        $this->assertFalse(Schema::hasTable('trailers'));
        $this->assertTrue(Schema::hasColumn('movie', 'trailer'));
        $this->assertDatabaseHas('movie', [
            'num' => 1,
            'trailer' => 'https://example.com/trailer.m3u8',
        ]);
    }
}
