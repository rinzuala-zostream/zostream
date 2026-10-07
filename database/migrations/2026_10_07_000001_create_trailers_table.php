<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL commits CREATE TABLE before attempting the foreign-key ALTER.
        // A failed first run can therefore leave an empty table behind even
        // though Laravel did not record the migration. Recover only when that
        // partial table is empty so existing data is never discarded.
        if (Schema::hasTable('trailers')) {
            if (DB::table('trailers')->exists()) {
                throw new RuntimeException('The unrecorded trailers table contains data; refusing to replace it.');
            }

            Schema::drop('trailers');
        }

        Schema::create('trailers', function (Blueprint $table) {
            $table->id();
            // The legacy movie.num column is signed on production. Keep the
            // same scalar type for joins, while the unique index enforces the
            // one-trailer-per-movie relationship.
            $table->integer('movie_id')->unique();
            $table->text('url');
            $table->timestamps();
        });

        if (Schema::hasColumn('movie', 'trailer')) {
            DB::table('movie')
                ->select(['num', 'trailer'])
                ->whereNotNull('trailer')
                ->whereRaw("TRIM(trailer) <> ''")
                ->orderBy('num')
                ->chunkById(200, function ($movies): void {
                    $now = now();
                    $rows = $movies->map(fn ($movie): array => [
                        'movie_id' => $movie->num,
                        'url' => trim((string) $movie->trailer),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();

                    DB::table('trailers')->insert($rows);
                }, 'num');

            Schema::table('movie', function (Blueprint $table) {
                $table->dropColumn('trailer');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('movie', 'trailer')) {
            Schema::table('movie', function (Blueprint $table) {
                $table->text('trailer')->nullable();
            });

            DB::table('trailers')
                ->select(['id', 'movie_id', 'url'])
                ->orderBy('id')
                ->chunkById(200, function ($trailers): void {
                    foreach ($trailers as $trailer) {
                        DB::table('movie')
                            ->where('num', $trailer->movie_id)
                            ->update(['trailer' => $trailer->url]);
                    }
                });
        }

        Schema::dropIfExists('trailers');
    }
};
