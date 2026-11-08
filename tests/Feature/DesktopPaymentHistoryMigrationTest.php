<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DesktopPaymentHistoryMigrationTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = (string) config('database.default');
        config(['database.connections.desktop_payment_testing' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('desktop_payment_testing');
        Schema::create('n_payment_histories', function (Blueprint $table) {
            $table->id();
            $table->enum('device_type', ['mobile', 'browser', 'tv', 'custom'])->nullable()->default('mobile');
            $table->string('transaction_id')->unique();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('desktop_payment_testing');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_expansion_preserves_history_custom_types_defaults_nullability_and_indexes(): void
    {
        foreach (['mobile', 'browser', 'tv', 'custom', null] as $index => $type) {
            DB::table('n_payment_histories')->insert(['device_type' => $type, 'transaction_id' => "old-$index"]);
        }
        $migration = $this->migration();
        $migration->up();
        $migration->up();
        $this->assertSame(['mobile', 'browser', 'tv', 'custom', null], DB::table('n_payment_histories')->orderBy('id')->pluck('device_type')->all());
        DB::table('n_payment_histories')->insert(['device_type' => 'desktop', 'transaction_id' => 'desktop']);
        DB::table('n_payment_histories')->insert(['transaction_id' => 'default']);
        $this->assertSame('mobile', DB::table('n_payment_histories')->where('transaction_id', 'default')->value('device_type'));
        $this->assertTrue(Schema::hasIndex('n_payment_histories', ['transaction_id'], 'unique'));
        $migration->down();
        $this->assertSame('desktop', DB::table('n_payment_histories')->where('transaction_id', 'desktop')->value('device_type'));
    }

    public function test_varchar_columns_are_not_restricted_and_missing_tables_are_safe(): void
    {
        Schema::drop('n_payment_histories');
        $this->migration()->up();
        $this->assertFalse(Schema::hasTable('n_payment_histories'));
        Schema::create('n_payment_histories', function (Blueprint $table) {
            $table->id();
            $table->string('device_type', 24)->nullable();
        });
        $this->migration()->up();
        DB::table('n_payment_histories')->insert(['device_type' => 'another-category']);
        $this->assertSame('another-category', DB::table('n_payment_histories')->value('device_type'));
    }

    public function test_mysql_alter_preserves_enum_values_default_comment_and_collation(): void
    {
        $connection = new \Illuminate\Database\MySqlConnection(function () {
            throw new \LogicException('SQL compilation must not connect to a database.');
        });
        $connection->setSchemaGrammar(new \Illuminate\Database\Schema\Grammars\MySqlGrammar($connection));
        $sql = [];
        Schema::shouldReceive('hasColumn')->once()->with('n_payment_histories', 'device_type')->andReturn(true);
        Schema::shouldReceive('getColumns')->once()->with('n_payment_histories')->andReturn([[
            'name' => 'device_type', 'type' => "enum('mobile','browser','tv','custom')",
            'nullable' => true, 'default' => "'custom'", 'comment' => 'Billing device', 'collation' => 'utf8mb4_unicode_ci',
        ]]);
        Schema::shouldReceive('table')->once()->with('n_payment_histories', \Mockery::type('Closure'))
            ->andReturnUsing(function ($name, $callback) use ($connection, &$sql) {
                $blueprint = new Blueprint($connection, $name);
                $callback($blueprint);
                $sql = $blueprint->toSql();
            });
        $this->migration()->up();
        $this->assertCount(1, $sql);
        $this->assertStringContainsString("enum('mobile', 'browser', 'tv', 'custom', 'desktop')", $sql[0]);
        $this->assertStringContainsString("null default 'custom'", $sql[0]);
        $this->assertStringContainsString("comment 'Billing device'", $sql[0]);
        $this->assertStringContainsString("collate 'utf8mb4_unicode_ci'", $sql[0]);
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_11_03_000001_add_desktop_to_payment_history_device_type.php');
    }
}
