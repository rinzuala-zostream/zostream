<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('n_payment_histories', 'device_type')) {
            return;
        }

        $column = collect(Schema::getColumns('n_payment_histories'))->firstWhere('name', 'device_type');
        $type = $column['type'];
        if (str_starts_with(strtolower($type), 'enum(')) {
            $enum = $type;
        } elseif (DB::getDriverName() === 'sqlite') {
            // SQLite reports Laravel enums as varchar; retain the actual CHECK values.
            $sql = DB::selectOne("select sql from sqlite_master where type = 'table' and name = ?", ['n_payment_histories'])->sql ?? '';
            if (! preg_match('/["`\\[]?device_type["`\\]]?\s+in\s*\(([^)]*)\)/i', $sql, $matches)) {
                return;
            }
            $enum = $matches[1];
        } else {
            // Varchar columns already accept desktop and must keep their definition.
            return;
        }

        preg_match_all("/'((?:[^']|'')*)'/", $enum, $matches);
        $values = array_map(fn ($value) => str_replace("''", "'", $value), $matches[1]);
        if (in_array('desktop', $values, true)) {
            return;
        }
        $values[] = 'desktop';
        $default = $column['default'];
        if (is_string($default) && str_starts_with($default, "'")) {
            $default = str_replace("''", "'", substr($default, 1, -1));
        }

        Schema::table('n_payment_histories', function (Blueprint $table) use ($column, $values, $default) {
            $definition = $table->enum('device_type', $values)->nullable($column['nullable']);
            if ($default !== null) {
                $definition->default($default);
            }
            if ($column['collation'] ?? null) {
                $definition->collation($column['collation']);
            }
            if ($column['comment'] ?? null) {
                $definition->comment($column['comment']);
            }
            $definition->change();
        });
    }

    public function down(): void
    {
        // Keep the expanded enum: historical payment records must remain valid.
    }
};
