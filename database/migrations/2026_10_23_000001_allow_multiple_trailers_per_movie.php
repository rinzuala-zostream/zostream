<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trailers', function (Blueprint $table): void {
            $table->dropUnique('trailers_movie_id_unique');
            $table->index('movie_id');
        });
    }

    public function down(): void
    {
        Schema::table('trailers', function (Blueprint $table): void {
            $table->dropIndex(['movie_id']);
            $table->unique('movie_id');
        });
    }
};
