<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_sections', function (Blueprint $table) {
            $table->id();
            $table->string('section_key')->unique();
            $table->string('title');
            $table->unsignedSmallInteger('position')->default(0)->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestamps();
        });

        $now = now();
        $sections = [
            ['section_key' => 'latest_update', 'title' => 'Latest Update'],
            ['section_key' => 'continue_watching', 'title' => 'Continue Watching'],
            ['section_key' => 'because_you_watched', 'title' => 'Because You Watched'],
            ['section_key' => 'top_picks_for_you', 'title' => 'Top Picks for You'],
            ['section_key' => 'similar_movies', 'title' => 'Similar Movies'],
            ['section_key' => 'trending_now', 'title' => 'Trending Now'],
            ['section_key' => 'new_releases', 'title' => 'New Releases'],
            ['section_key' => 'your_wishlist', 'title' => 'Your Wishlist'],
            ['section_key' => 'next_episode', 'title' => 'Next Episode'],
        ];

        DB::table('home_sections')->insert(array_map(
            fn (array $section, int $position): array => $section + [
                'position' => $position,
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $sections,
            array_keys($sections)
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('home_sections');
    }
};
