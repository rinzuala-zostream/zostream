<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LOOKUPS = [
        'n_subscriptions' => ['engagement_subscription_user_idx', ['user_id', 'is_active', 'end_at']],
        'n_devices' => ['engagement_device_user_idx', ['user_id']],
        'wist_list' => ['engagement_wishlist_user_idx', ['uid', 'created_at']],
    ];

    public function up(): void
    {
        foreach (self::LOOKUPS as $table => [$name, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            // A pre-existing index with the same leading column already bounds lookup work.
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['columns'][0] ?? null) === $columns[0]) {
                    continue 2;
                }
            }
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        }
    }

    public function down(): void
    {
        foreach (self::LOOKUPS as $table => [$name]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (Schema::getIndexes($table) as $index) {
                if ($index['name'] === $name) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
                }
            }
        }
    }
};
