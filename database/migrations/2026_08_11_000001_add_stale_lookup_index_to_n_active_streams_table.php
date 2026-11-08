<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'n_active_streams_stale_lookup_idx';

    public function up(): void
    {
        if (! Schema::hasTable('n_active_streams') || $this->indexExists()) {
            return;
        }

        Schema::table('n_active_streams', function (Blueprint $table) {
            $table->index(['status', 'last_ping'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('n_active_streams') || ! $this->indexExists()) {
            return;
        }

        Schema::table('n_active_streams', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }

    private function indexExists(): bool
    {
        foreach (Schema::getIndexes('n_active_streams') as $index) {
            if (($index['name'] ?? null) === self::INDEX) {
                return true;
            }
        }

        return false;
    }
};
