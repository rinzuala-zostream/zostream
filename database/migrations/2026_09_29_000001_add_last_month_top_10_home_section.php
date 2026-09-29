<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('home_sections')) {
            return;
        }

        $values = [
            'title' => 'Last Month Top 10',
            'position' => ((int) DB::table('home_sections')->max('position')) + 1,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('home_sections', 'source_key')) {
            $values['source_key'] = 'last_month_top_10';
        }

        DB::table('home_sections')->updateOrInsert(
            ['section_key' => 'last_month_top_10'],
            $values
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('home_sections')) {
            DB::table('home_sections')->where('section_key', 'last_month_top_10')->delete();
        }
    }
};
