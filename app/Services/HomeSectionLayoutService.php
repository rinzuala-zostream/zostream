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

    public static function functions(): array
    {
        return [
            ['key' => 'latest_update', 'title' => 'Latest Update', 'description' => 'Recently updated published content.'],
            ['key' => 'continue_watching', 'title' => 'Continue Watching', 'description' => 'Incomplete watch progress for the signed-in user.'],
            ['key' => 'because_you_watched', 'title' => 'Because You Watched', 'description' => 'Recommendations based on a recent watched title.'],
            ['key' => 'top_picks_for_you', 'title' => 'Top Picks for You', 'description' => 'Personalized hybrid recommendation ranking.'],
            ['key' => 'similar_movies', 'title' => 'Similar Movies', 'description' => 'Content similar to the user’s favourite title.'],
            ['key' => 'trending_now', 'title' => 'Trending Now', 'description' => 'Strong recent viewing activity.'],
            ['key' => 'new_releases', 'title' => 'New Releases', 'description' => 'Newest enabled and published releases.'],
            ['key' => 'your_wishlist', 'title' => 'Your Wishlist', 'description' => 'The signed-in user’s wishlist.'],
            ['key' => 'next_episode', 'title' => 'Next Episode', 'description' => 'The next published episode for recently watched series.'],
        ];
    }

    public function all(): array
    {
        if (! Schema::hasTable('home_sections')) {
            return $this->defaultRows();
        }

        $hasSourceKey = Schema::hasColumn('home_sections', 'source_key');
        $stored = HomeSection::query()->get()->keyBy('section_key');
        $rows = [];
        foreach (self::DEFAULTS as $key => $defaultTitle) {
            $row = $stored->get($key);
            $rows[] = [
                'key' => $key,
                'source_key' => $hasSourceKey ? ($row?->source_key ?: $key) : $key,
                'title' => $row?->title ?: $defaultTitle,
                'position' => $row?->position ?? array_search($key, self::keys(), true),
                'is_enabled' => $row?->is_enabled ?? false,
                'is_custom' => false,
            ];
        }

        foreach ($stored as $key => $row) {
            if (array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'source_key' => $hasSourceKey ? ($row->source_key ?: 'latest_update') : 'latest_update',
                'title' => $row->title,
                'position' => $row->position,
                'is_enabled' => $row->is_enabled,
                'is_custom' => true,
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

    public function definition(string $key): ?array
    {
        foreach ($this->all() as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        return null;
    }

    public function replace(array $sections): array
    {
        DB::transaction(function () use ($sections): void {
            $hasSourceKey = Schema::hasColumn('home_sections', 'source_key');
            foreach (self::DEFAULTS as $key => $title) {
                $defaults = ['title' => $title, 'position' => 0, 'is_enabled' => false];
                if ($hasSourceKey) {
                    $defaults['source_key'] = $key;
                }
                HomeSection::query()->firstOrCreate(
                    ['section_key' => $key],
                    $defaults
                );
            }

            HomeSection::query()->update(['is_enabled' => false]);

            foreach ($sections as $position => $section) {
                $values = [
                    'title' => trim($section['title']),
                    'position' => $position,
                    'is_enabled' => true,
                ];
                if ($hasSourceKey) {
                    $values['source_key'] = $section['source_key'];
                }
                HomeSection::query()->updateOrCreate(
                    ['section_key' => $section['key']],
                    $values
                );
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
                'source_key' => $key,
                'title' => $title,
                'position' => count($rows),
                'is_enabled' => true,
                'is_custom' => false,
            ];
        }

        return $rows;
    }
}
