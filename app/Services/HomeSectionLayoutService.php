<?php

namespace App\Services;

use App\Models\HomeSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomeSectionLayoutService
{
    public const DEFAULTS = [
        'latest_update' => 'Latest Update',
        'continue_watching' => 'Continue Watching',
        'because_you_watched' => 'Because You Watched',
        'top_picks_for_you' => 'Top Picks for You',
        'similar_movies' => 'Similar Movies',
        'trending_now' => 'Trending Now',
        'new_releases' => 'New Releases',
        'your_wishlist' => 'Your Wishlist',
        'next_episode' => 'Next Episode',
    ];

    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    public function all(): array
    {
        if (! Schema::hasTable('home_sections')) {
            return $this->defaultRows();
        }

        $stored = HomeSection::query()->get()->keyBy('section_key');
        $rows = [];
        foreach (self::DEFAULTS as $key => $defaultTitle) {
            $row = $stored->get($key);
            $rows[] = [
                'key' => $key,
                'title' => $row?->title ?: $defaultTitle,
                'position' => $row?->position ?? array_search($key, self::keys(), true),
                'is_enabled' => $row?->is_enabled ?? false,
            ];
        }

        usort($rows, fn (array $left, array $right): int => [
            ! $left['is_enabled'],
            $left['position'],
            array_search($left['key'], self::keys(), true),
        ] <=> [
            ! $right['is_enabled'],
            $right['position'],
            array_search($right['key'], self::keys(), true),
        ]);

        return $rows;
    }

    public function enabled(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (array $section): bool => $section['is_enabled']
        ));
    }

    public function definition(string $key): array
    {
        foreach ($this->all() as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        return [
            'key' => $key,
            'title' => self::DEFAULTS[$key] ?? $key,
            'position' => 0,
            'is_enabled' => false,
        ];
    }

    public function replace(array $sections): array
    {
        DB::transaction(function () use ($sections): void {
            foreach (self::DEFAULTS as $key => $title) {
                HomeSection::query()->firstOrCreate(
                    ['section_key' => $key],
                    ['title' => $title, 'position' => 0, 'is_enabled' => false]
                );
            }

            HomeSection::query()
                ->whereIn('section_key', self::keys())
                ->update(['is_enabled' => false]);

            foreach ($sections as $position => $section) {
                HomeSection::query()
                    ->where('section_key', $section['key'])
                    ->update([
                        'title' => trim($section['title']),
                        'position' => $position,
                        'is_enabled' => true,
                    ]);
            }
        });

        return $this->all();
    }

    private function defaultRows(): array
    {
        $rows = [];
        foreach (self::DEFAULTS as $key => $title) {
            $rows[] = [
                'key' => $key,
                'title' => $title,
                'position' => count($rows),
                'is_enabled' => true,
            ];
        }

        return $rows;
    }
}
