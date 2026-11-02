<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        ['watch_sessions', ['user_id', 'created_at'], 'stats_watch_user_created_idx'],
        ['bandwidth_logs', ['user_id', 'created_at'], 'stats_bandwidth_user_created_idx'],
    ];

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        foreach (self::INDEXES as [$name, $columns, $indexName]) {
            if (! $schema->hasTable($name) || ! $schema->hasColumns($name, $columns)) {
                continue;
            }
            foreach ($schema->getIndexes($name) as $index) {
                if ($index['name'] === $indexName || array_slice($index['columns'], 0, count($columns)) === $columns) {
                    continue 2;
                }
            }
            $schema->table($name, fn (Blueprint $table) => $table->index($columns, $indexName));
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        foreach (self::INDEXES as [$name, $columns, $indexName]) {
            if ($schema->hasTable($name) && $schema->hasIndex($name, $indexName)) {
                $schema->table($name, fn (Blueprint $table) => $table->dropIndex($indexName));
            }
        }
    }
};
