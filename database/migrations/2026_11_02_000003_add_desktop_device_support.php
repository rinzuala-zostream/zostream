<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['n_devices', 'n_active_streams', 'qr_auth_sessions', 'n_plans'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->changeDeviceTypes($table, true);
        }

        if (Schema::hasTable('n_devices') && ! Schema::hasColumn('n_devices', 'platform')) {
            Schema::table('n_devices', function (Blueprint $table) {
                $table->string('platform', 24)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Narrowing an enum must never silently delete or reclassify desktop data.
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'device_type')
                && DB::table($table)->where('device_type', 'desktop')->exists()) {
                throw new RuntimeException('Cannot roll back desktop support while desktop records exist in '.$table.'.');
            }
        }

        foreach (self::TABLES as $table) {
            $this->changeDeviceTypes($table, false);
        }

        if (Schema::hasColumn('n_devices', 'platform')) {
            Schema::table('n_devices', fn (Blueprint $table) => $table->dropColumn('platform'));
        }
    }

    private function changeDeviceTypes(string $name, bool $addDesktop): void
    {
        if (! Schema::hasColumn($name, 'device_type')) {
            return;
        }

        $column = collect(Schema::getColumns($name))->firstWhere('name', 'device_type');
        $type = $column['type'];
        if (str_starts_with(strtolower($type), 'enum(')) {
            preg_match_all("/'((?:[^']|'')*)'/", $type, $matches);
            $values = array_map(fn ($value) => str_replace("''", "'", $value), $matches[1]);
        } elseif (DB::getDriverName() === 'sqlite' && $name !== 'n_plans') {
            // Laravel represents SQLite enums as varchar with a CHECK constraint.
            $values = ['mobile', 'browser', 'tv'];
        } else {
            // Existing varchar columns already accept desktop; retain their definition.
            return;
        }

        $values = array_values(array_diff($values, ['desktop']));
        if ($addDesktop) {
            $values[] = 'desktop';
        }
        $default = $column['default'];
        if (is_string($default) && str_starts_with($default, "'")) {
            $default = str_replace("''", "'", substr($default, 1, -1));
        }

        Schema::table($name, function (Blueprint $table) use ($column, $values, $default) {
            $definition = $table->enum('device_type', $values)->nullable($column['nullable']);
            if ($default !== null) {
                $definition->default($default);
            }
            if ($column['comment'] ?? null) {
                $definition->comment($column['comment']);
            }
            $definition->change();
        });
    }
};
