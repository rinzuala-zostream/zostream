<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DesktopDeviceMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.desktop_migration_testing' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('desktop_migration_testing');
        foreach (['n_devices', 'n_active_streams', 'qr_auth_sessions'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $column = $table->enum('device_type', ['mobile', 'browser', 'tv']);
                if ($name === 'qr_auth_sessions') {
                    $column->default('browser');
                }
                $table->string('device_token')->unique();
            });
            foreach (['mobile', 'browser', 'tv'] as $type) {
                DB::table($name)->insert(['device_type' => $type, 'device_token' => $type]);
            }
        }
        Schema::create('n_plans', function (Blueprint $table) {
            $table->id();
            $table->string('device_type');
        });
    }

    protected function tearDown(): void
    {
        DB::purge('desktop_migration_testing');
        parent::tearDown();
    }

    public function test_migration_adds_desktop_and_preserves_rows_defaults_and_indexes(): void
    {
        $migration = $this->migration();
        $migration->up();

        foreach (['n_devices', 'n_active_streams', 'qr_auth_sessions'] as $name) {
            $this->assertSame(['mobile', 'browser', 'tv'], DB::table($name)->orderBy('id')->pluck('device_type')->all());
            DB::table($name)->insert(['device_type' => 'desktop', 'device_token' => 'desktop']);
            $this->assertTrue(Schema::hasIndex($name, ['device_token'], 'unique'));
        }
        DB::table('qr_auth_sessions')->insert(['device_token' => 'default-type']);
        $this->assertSame('browser', DB::table('qr_auth_sessions')->where('device_token', 'default-type')->value('device_type'));
        DB::table('n_plans')->insert(['device_type' => 'desktop']);
        DB::table('n_devices')->where('device_type', 'desktop')->update(['platform' => 'windows']);
        $this->assertSame('windows', DB::table('n_devices')->where('device_type', 'desktop')->value('platform'));
    }

    public function test_rollback_refuses_to_reclassify_or_delete_desktop_records(): void
    {
        $migration = $this->migration();
        $migration->up();
        DB::table('n_devices')->insert(['device_type' => 'desktop', 'device_token' => 'desktop', 'platform' => 'macos']);
        try {
            $migration->down();
            $this->fail('Rollback must preserve desktop data.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('desktop records exist', $error->getMessage());
        }
        $this->assertSame('macos', DB::table('n_devices')->where('device_token', 'desktop')->value('platform'));
        $this->assertSame(4, DB::table('n_devices')->count());
    }

    public function test_rollback_without_desktop_records_retains_legacy_data(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('n_devices', 'platform'));
        foreach (['n_devices', 'n_active_streams', 'qr_auth_sessions'] as $name) {
            $this->assertSame(['mobile', 'browser', 'tv'], DB::table($name)->orderBy('id')->pluck('device_type')->all());
        }
        $this->expectException(QueryException::class);
        DB::table('n_devices')->insert(['device_type' => 'desktop', 'device_token' => 'must-reject']);
    }

    public function test_mysql_enum_expansion_preserves_existing_values_and_column_attributes(): void
    {
        $connection = new \Illuminate\Database\MySqlConnection(function () {
            throw new \LogicException('SQL compilation must not connect to a database.');
        });
        $grammar = new \Illuminate\Database\Schema\Grammars\MySqlGrammar($connection);
        $connection->setSchemaGrammar($grammar);
        $sql = [];
        Schema::shouldReceive('hasColumn')->once()->with('n_devices', 'device_type')->andReturn(true);
        Schema::shouldReceive('getColumns')->once()->with('n_devices')->andReturn([[
            'name' => 'device_type', 'type' => "enum('mobile','browser','tv','custom')",
            'nullable' => true, 'default' => 'browser', 'comment' => 'Device category',
        ]]);
        Schema::shouldReceive('table')->once()->with('n_devices', \Mockery::type('Closure'))
            ->andReturnUsing(function ($name, $callback) use ($connection, &$sql) {
                $blueprint = new Blueprint($connection, $name);
                $callback($blueprint);
                $sql = $blueprint->toSql();
            });
        $migration = $this->migration();
        (new \ReflectionMethod($migration, 'changeDeviceTypes'))->invoke($migration, 'n_devices', true);
        $this->assertCount(1, $sql);
        $this->assertStringContainsString("enum('mobile', 'browser', 'tv', 'custom', 'desktop')", $sql[0]);
        $this->assertStringContainsString("null default 'browser'", $sql[0]);
        $this->assertStringContainsString("comment 'Device category'", $sql[0]);
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_11_02_000003_add_desktop_device_support.php');
    }
}
