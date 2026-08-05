<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('n_active_streams')) {
            return;
        }

        if (Schema::hasColumn('n_active_streams', 'subscription_id')) {
            Schema::table('n_active_streams', function (Blueprint $table) {
                // Free and PPV playback does not necessarily have a subscription.
                $table->unsignedBigInteger('subscription_id')->nullable()->change();
            });
        }

        if (! Schema::hasColumn('n_active_streams', 'content_key')) {
            Schema::table('n_active_streams', function (Blueprint $table) {
                $table->string('content_key', 225)->nullable()->after('content_id');
            });
        }

        Schema::table('n_active_streams', function (Blueprint $table) {
            if (! $this->indexExists('n_active_streams', 'n_active_streams_token_idx')) {
                $table->index('stream_token', 'n_active_streams_token_idx');
            }

            if (! $this->indexExists('n_active_streams', 'n_active_streams_seat_lookup_idx')) {
                $table->index(
                    ['subscription_id', 'device_type', 'status', 'last_ping'],
                    'n_active_streams_seat_lookup_idx'
                );
            }

            if (! $this->indexExists('n_active_streams', 'n_active_streams_device_content_idx')) {
                $table->index(
                    ['device_id', 'content_type', 'content_key', 'status'],
                    'n_active_streams_device_content_idx'
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('n_active_streams')) {
            return;
        }

        Schema::table('n_active_streams', function (Blueprint $table) {
            foreach ([
                'n_active_streams_token_idx',
                'n_active_streams_seat_lookup_idx',
                'n_active_streams_device_content_idx',
            ] as $indexName) {
                if ($this->indexExists('n_active_streams', $indexName)) {
                    $table->dropIndex($indexName);
                }
            }
        });

        if (Schema::hasColumn('n_active_streams', 'content_key')) {
            Schema::table('n_active_streams', function (Blueprint $table) {
                $table->dropColumn('content_key');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
