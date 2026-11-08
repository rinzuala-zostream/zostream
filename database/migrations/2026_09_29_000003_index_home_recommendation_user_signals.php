<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WATCH_INDEX = 'watch_position_user_updated_index';

    private const WISHLIST_INDEX = 'wist_list_uid_updated_index';

    public function up(): void
    {
        $this->addIndexIfPossible(
            'watch_position',
            ['user_id', 'updated_at'],
            self::WATCH_INDEX
        );
        $this->addIndexIfPossible(
            'wist_list',
            ['uid', 'updated_at'],
            self::WISHLIST_INDEX
        );
    }

    public function down(): void
    {
        $this->dropIndexIfPresent('watch_position', self::WATCH_INDEX);
        $this->dropIndexIfPresent('wist_list', self::WISHLIST_INDEX);
    }

    private function addIndexIfPossible(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndexIfPresent(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
