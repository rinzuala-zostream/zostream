<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'watch_position_updated_at_index';

    public function up(): void
    {
        if (! Schema::hasTable('watch_position')
            || ! Schema::hasColumn('watch_position', 'updated_at')
            || $this->indexExists()) {
            return;
        }

        Schema::table('watch_position', function (Blueprint $table): void {
            $table->index('updated_at', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('watch_position') || ! $this->indexExists()) {
            return;
        }

        Schema::table('watch_position', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    private function indexExists(): bool
    {
        foreach (Schema::getIndexes('watch_position') as $index) {
            if (($index['name'] ?? null) === self::INDEX) {
                return true;
            }
        }

        return false;
    }
};
