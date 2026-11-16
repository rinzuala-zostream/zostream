<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('movie') && !Schema::hasColumn('movie', 'hero_img')) {
            Schema::table('movie', function (Blueprint $table) {
                $table->text('hero_img')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('movie') && Schema::hasColumn('movie', 'hero_img')) {
            Schema::table('movie', function (Blueprint $table) {
                $table->dropColumn('hero_img');
            });
        }
    }
};
