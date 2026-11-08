<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('n_active_streams') || Schema::hasColumn('n_active_streams', 'viewed_at')) {
            return;
        }

        Schema::table('n_active_streams', function (Blueprint $table) {
            $table->dateTime('viewed_at')->nullable()->after('last_ping');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('n_active_streams') || ! Schema::hasColumn('n_active_streams', 'viewed_at')) {
            return;
        }

        Schema::table('n_active_streams', function (Blueprint $table) {
            $table->dropColumn('viewed_at');
        });
    }
};
