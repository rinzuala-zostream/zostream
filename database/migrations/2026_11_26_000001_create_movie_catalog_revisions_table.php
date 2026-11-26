<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_catalog_revisions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(1);
        });

        DB::table('movie_catalog_revisions')->insert(['id' => 1, 'revision' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_catalog_revisions');
    }
};
