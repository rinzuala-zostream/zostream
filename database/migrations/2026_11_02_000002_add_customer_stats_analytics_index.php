<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'analytics';

    private const INDEXES = [['playback_sessions', ['user_id', 'ended_at'], 'stats_playback_user_ended_idx']];

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
