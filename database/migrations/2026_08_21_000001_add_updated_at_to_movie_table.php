<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('movie') || Schema::hasColumn('movie', 'updated_at')) {
            return;
        }

        Schema::table('movie', function (Blueprint $table) {
            $table->timestamp('updated_at')->nullable()->after('create_date');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('movie') || !Schema::hasColumn('movie', 'updated_at')) {
            return;
        }

        Schema::table('movie', function (Blueprint $table) {
            $table->dropColumn('updated_at');
        });
    }
};
