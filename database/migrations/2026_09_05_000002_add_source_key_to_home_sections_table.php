<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('home_sections', function (Blueprint $table): void {
            $table->string('source_key')->nullable()->after('section_key')->index();
        });

        DB::table('home_sections')->update([
            'source_key' => DB::raw('section_key'),
        ]);
    }

    public function down(): void
    {
        Schema::table('home_sections', function (Blueprint $table): void {
            $table->dropIndex(['source_key']);
            $table->dropColumn('source_key');
        });
    }
};
