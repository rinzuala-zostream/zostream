<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('n_subscriptions', function (Blueprint $table) {
            if (!$this->indexExists('n_subscriptions', 'n_subscriptions_dashboard_end_idx')) {
                $table->index(['is_active', 'end_at'], 'n_subscriptions_dashboard_end_idx');
            }

            if (!$this->indexExists('n_subscriptions', 'n_subscriptions_dashboard_created_idx')) {
                $table->index(['is_active', 'created_at'], 'n_subscriptions_dashboard_created_idx');
            }

            if (!$this->indexExists('n_subscriptions', 'n_subscriptions_dashboard_start_idx')) {
                $table->index(['is_active', 'start_at'], 'n_subscriptions_dashboard_start_idx');
            }
        });

        if (Schema::hasColumn('n_plans', 'device_type')) {
            Schema::table('n_plans', function (Blueprint $table) {
                if (!$this->indexExists('n_plans', 'n_plans_device_type_idx')) {
                    $table->index('device_type', 'n_plans_device_type_idx');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('n_subscriptions', function (Blueprint $table) {
            foreach ([
                'n_subscriptions_dashboard_end_idx',
                'n_subscriptions_dashboard_created_idx',
                'n_subscriptions_dashboard_start_idx',
            ] as $indexName) {
                if ($this->indexExists('n_subscriptions', $indexName)) {
                    $table->dropIndex($indexName);
                }
            }
        });

        if (Schema::hasColumn('n_plans', 'device_type')) {
            Schema::table('n_plans', function (Blueprint $table) {
                if ($this->indexExists('n_plans', 'n_plans_device_type_idx')) {
                    $table->dropIndex('n_plans_device_type_idx');
                }
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $quotedTable = str_replace('`', '``', $table);
        $indexes = DB::select("SHOW INDEX FROM `{$quotedTable}` WHERE Key_name = ?", [$indexName]);

        return count($indexes) > 0;
    }
};
