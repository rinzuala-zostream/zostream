<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('n_active_streams')) {
            return;
        }

        if (! Schema::hasColumn('n_active_streams', 'content_type')) {
            Schema::table('n_active_streams', function (Blueprint $table) {
                $table->string('content_type', 32)->nullable()->after('device_type');
            });
        }

        if (! Schema::hasColumn('n_active_streams', 'content_id')) {
            Schema::table('n_active_streams', function (Blueprint $table) {
                $table->unsignedBigInteger('content_id')->nullable()->after('content_type');
            });
        }

        if (! $this->indexExists('n_active_streams_content_type_content_id_index')) {
            Schema::table('n_active_streams', function (Blueprint $table) {
                $table->index(['content_type', 'content_id']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('n_active_streams')) {
            return;
        }

        Schema::table('n_active_streams', function (Blueprint $table) {
            if ($this->indexExists('n_active_streams_content_type_content_id_index')) {
                $table->dropIndex('n_active_streams_content_type_content_id_index');
            }

            $columns = array_values(array_filter(
                ['content_type', 'content_id'],
                fn (string $column) => Schema::hasColumn('n_active_streams', $column)
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function indexExists(string $indexName): bool
    {
        foreach (Schema::getIndexes('n_active_streams') as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
