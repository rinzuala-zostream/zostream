<?php

namespace App\Services;

use App\Models\MovieModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LiveHomeSectionService
{
    private const LIVE_SECTIONS = [
        'continue_watching',
        'trending_now',
        'new_releases',
        'your_wishlist',
        'next_episode',
    ];

    private const MOVIE_CARD_COLUMNS = [
        'id',
        'title',
        'genre',
        'poster',
        'cover_img',
        'isPremium',
        'isPayPerView',
        'release_on',
    ];

    public function snapshot(
        string $userId,
        int $limit,
        string $mode,
        bool $includeAgeRestricted,
        ?array $requestedSections = null,
        bool $includeRecommendationSignals = true
    ): array {
        $fetchLimit = min(max($limit, 20), 1251);
        $requested = $requestedSections === null
            ? self::LIVE_SECTIONS
            : array_values(array_intersect(self::LIVE_SECTIONS, $requestedSections));
        $needsWatch = $includeRecommendationSignals
            || in_array('continue_watching', $requested, true)
            || in_array('next_episode', $requested, true);
        $needsWishlist = $includeRecommendationSignals
            || in_array('your_wishlist', $requested, true);

        $watch = $needsWatch
            ? DB::table('watch_position')
                ->where('user_id', $userId)
                ->orderByRaw('COALESCE(updated_at, created_at) DESC')
                ->limit(1000)
                ->get(['movie_id', 'movie_type', 'position', 'duration', 'created_at', 'updated_at'])
                ->map(fn ($row) => (array) $row)->all()
            : [];
        $wishlist = $needsWishlist
            ? DB::table('wist_list')
                ->where('uid', $userId)
                ->orderByRaw('COALESCE(updated_at, created_at) DESC')
                ->limit(max($fetchLimit, 200))
                ->get(['movie_id', 'created_at', 'updated_at'])
                ->map(fn ($row) => (array) $row)->all()
            : [];

        $sections = [];
        if (in_array('continue_watching', $requested, true)) {
            $sections['continue_watching'] = $this->continueWatching($watch, $fetchLimit, $mode, $includeAgeRestricted);
        }
        if (in_array('trending_now', $requested, true)) {
            $sections['trending_now'] = $this->movies(
                $this->allowedMovies($mode, $includeAgeRestricted)->orderByDesc('views')->orderByDesc('num')->limit($fetchLimit)->get(self::MOVIE_CARD_COLUMNS)
            );
        }
        if (in_array('new_releases', $requested, true)) {
            $sections['new_releases'] = $this->movies(
                $this->allowedMovies($mode, $includeAgeRestricted)->whereNotNull('release_on')->orderByDesc('release_on')->orderByDesc('num')->limit($fetchLimit)->get(self::MOVIE_CARD_COLUMNS)
            );
        }
        if (in_array('your_wishlist', $requested, true)) {
            $sections['your_wishlist'] = $this->wishlist($wishlist, $fetchLimit, $mode, $includeAgeRestricted);
        }
        if (in_array('next_episode', $requested, true)) {
            $sections['next_episode'] = $this->nextEpisodes($watch, $fetchLimit, $mode, $includeAgeRestricted);
        }

        $signals = ['watch_position' => $watch, 'wishlist' => $wishlist];

        return [
            'signals' => $signals,
            'sections' => $sections,
            // AI output only depends on user signals. Keeping volatile live shelves
            // out of this version prevents view counters from defeating the cache.
            'version' => hash('sha256', json_encode($signals, JSON_UNESCAPED_UNICODE)),
        ];
    }

    public function filterAiSections(array $homepage, string $mode, bool $includeAgeRestricted): array
    {
        $ids = [];
        foreach (['because_you_watched', 'top_picks_for_you', 'similar_movies'] as $key) {
            $section = $homepage[$key] ?? [];
            if (isset($section['anchor']['id'])) {
                $ids[] = (string) $section['anchor']['id'];
            }
            $items = isset($section['items']) ? $section['items'] : $section;
            foreach (is_array($items) ? $items : [] as $item) {
                if (isset($item['id'])) {
                    $ids[] = (string) $item['id'];
                }
            }
        }
        $allowed = $this->allowedMovies($mode, $includeAgeRestricted)
            ->whereIn('id', array_values(array_unique($ids)))
            ->pluck('id')->mapWithKeys(fn ($id) => [(string) $id => true])->all();

        foreach (['because_you_watched', 'top_picks_for_you', 'similar_movies'] as $key) {
            if (! isset($homepage[$key])) {
                continue;
            }
            if (isset($homepage[$key]['items'])) {
                $homepage[$key]['items'] = array_values(array_filter(
                    $homepage[$key]['items'], fn ($item) => isset($allowed[(string) ($item['id'] ?? '')])
                ));
                if (isset($homepage[$key]['anchor']['id']) && ! isset($allowed[(string) $homepage[$key]['anchor']['id']])) {
                    $homepage[$key]['anchor'] = null;
                }
            } else {
                $homepage[$key] = array_values(array_filter(
                    $homepage[$key], fn ($item) => isset($allowed[(string) ($item['id'] ?? '')])
                ));
            }
        }

        return $homepage;
    }

    private function allowedMovies(string $mode, bool $includeAgeRestricted): Builder
    {
        return MovieModel::query()
            ->where('status', 'Published')
            ->where('isEnable', 1)
            ->when($mode === 'kids', fn (Builder $query) => $query->where('isChildMode', 1))
            ->when($mode === 'kids' || ! $includeAgeRestricted, fn (Builder $query) => $query->where('isAgeRestricted', 0));
    }

    private function wishlist(array $wishlist, int $limit, string $mode, bool $includeAgeRestricted): array
    {
        $movieIds = collect($wishlist)->pluck('movie_id')->unique();
        $movies = $this->allowedMovies($mode, $includeAgeRestricted)
            ->whereIn('id', $movieIds)->get(self::MOVIE_CARD_COLUMNS)->keyBy('id');
        $cards = [];
        foreach ($movieIds as $movieId) {
            if ($movie = $movies->get($movieId)) {
                $cards[] = $this->movieCard($movie);
            }
            if (count($cards) >= $limit) {
                break;
            }
        }

        return $cards;
    }

    private function continueWatching(array $watch, int $limit, string $mode, bool $includeAgeRestricted): array
    {
        $movieIds = collect($watch)->where('movie_type', '!=', 'episode')->pluck('movie_id')->unique();
        $episodeIds = collect($watch)->where('movie_type', 'episode')->pluck('movie_id')->unique();
        $movies = $this->allowedMovies($mode, $includeAgeRestricted)->whereIn('id', $movieIds)->get(self::MOVIE_CARD_COLUMNS)->keyBy('id');
        $episodes = DB::table('episodes')->join('seasons', 'seasons.id', '=', 'episodes.season_id')
            ->join('movie', 'movie.num', '=', 'seasons.movie_id')
            ->whereIn('episodes.id', $episodeIds)->where('episodes.status', 'Published')->where('episodes.is_active', 1)
            ->where('movie.status', 'Published')->where('movie.isEnable', 1)
            ->when($mode === 'kids', fn ($q) => $q->where('movie.isChildMode', 1))
            ->when($mode === 'kids' || ! $includeAgeRestricted, fn ($q) => $q->where('movie.isAgeRestricted', 0))
            ->select('episodes.id', 'episodes.title', 'episodes.thumbnail', 'episodes.episode_number', 'seasons.season_number', 'movie.id as parent_id', 'movie.title as series_title', 'movie.isPremium as parent_premium', 'movie.isPayPerView as parent_ppv')
            ->get()->keyBy('id');
        $cards = [];
        foreach ($watch as $row) {
            $duration = (float) ($row['duration'] ?? 0);
            $position = (float) ($row['position'] ?? 0);
            $completion = $duration > 0 ? min($position / $duration, 1) : null;
            if ($position <= 0 || $completion === null || $completion >= .90) {
                continue;
            }
            if (($row['movie_type'] ?? '') === 'episode') {
                $episode = $episodes->get($row['movie_id']);
                if (! $episode) {
                    continue;
                }
                $card = $this->episodeCard($episode);
            } else {
                $movie = $movies->get($row['movie_id']);
                if (! $movie) {
                    continue;
                }
                $card = $this->movieCard($movie);
            }
            $card += ['position' => $position, 'duration' => $duration, 'completion' => round($completion, 6), 'updated_at' => $row['updated_at'] ?: $row['created_at']];
            $cards[$row['movie_type'].':'.$row['movie_id']] = $card;
            if (count($cards) >= $limit) {
                break;
            }
        }

        return array_values($cards);
    }

    private function nextEpisodes(array $watch, int $limit, string $mode, bool $includeAgeRestricted): array
    {
        $episodeIds = collect($watch)->where('movie_type', 'episode')->pluck('movie_id')->unique()->take(100);
        $watched = DB::table('episodes')->join('seasons', 'seasons.id', '=', 'episodes.season_id')
            ->whereIn('episodes.id', $episodeIds)
            ->select('episodes.id', 'episodes.episode_number', 'seasons.season_number', 'seasons.movie_id')
            ->orderByDesc('seasons.season_number')->orderByDesc('episodes.episode_number')->get()->unique('movie_id');
        if ($watched->isEmpty()) {
            return [];
        }

        // Fetch future episodes for every watched series in one query, then keep
        // the first result per series. This replaces up to 100 queries.
        $candidates = DB::table('episodes')->join('seasons', 'seasons.id', '=', 'episodes.season_id')
            ->join('movie', 'movie.num', '=', 'seasons.movie_id')
            ->where('episodes.status', 'Published')->where('episodes.is_active', 1)
            ->where('movie.status', 'Published')->where('movie.isEnable', 1)
            ->when($mode === 'kids', fn ($q) => $q->where('movie.isChildMode', 1))
            ->when($mode === 'kids' || ! $includeAgeRestricted, fn ($q) => $q->where('movie.isAgeRestricted', 0))
            ->where(function ($query) use ($watched) {
                foreach ($watched as $current) {
                    $query->orWhere(function ($series) use ($current) {
                        $series->where('seasons.movie_id', $current->movie_id)
                            ->where(function ($later) use ($current) {
                                $later->where('seasons.season_number', '>', $current->season_number)
                                    ->orWhere(function ($sameSeason) use ($current) {
                                        $sameSeason->where('seasons.season_number', $current->season_number)
                                            ->where('episodes.episode_number', '>', $current->episode_number);
                                    });
                            });
                    });
                }
            })
            ->select('episodes.id', 'episodes.title', 'episodes.thumbnail', 'episodes.episode_number', 'seasons.movie_id as series_id', 'seasons.season_number', 'movie.id as parent_id', 'movie.title as series_title', 'movie.isPremium as parent_premium', 'movie.isPayPerView as parent_ppv')
            ->orderBy('seasons.movie_id')->orderBy('seasons.season_number')->orderBy('episodes.episode_number')
            ->get()->unique('series_id')->keyBy('series_id');

        $cards = [];
        foreach ($watched as $current) {
            if ($next = $candidates->get($current->movie_id)) {
                $cards[] = $this->episodeCard($next) + ['reason' => 'Next unwatched episode'];
            }
            if (count($cards) >= $limit) {
                break;
            }
        }

        return $cards;
    }

    private function movies($movies): array
    {
        return $movies->map(fn ($movie) => $this->movieCard($movie))->values()->all();
    }

    private function movieCard($movie): array
    {
        return ['id' => (string) $movie->id, 'title' => (string) $movie->title, 'status' => 'Published', 'genre' => (string) ($movie->genre ?? ''), 'poster' => (string) ($movie->poster ?? ''), 'cover_img' => (string) ($movie->cover_img ?? ''), 'premium' => (bool) $movie->isPremium, 'ppv' => (bool) $movie->isPayPerView, 'release_on' => $movie->release_on ? (string) $movie->release_on : null];
    }

    private function episodeCard($episode): array
    {
        return ['id' => (string) $episode->id, 'parent_id' => (string) $episode->parent_id, 'series_title' => (string) $episode->series_title, 'title' => (string) $episode->title, 'status' => 'Published', 'season_number' => (int) $episode->season_number, 'episode_number' => (int) $episode->episode_number, 'thumbnail' => (string) ($episode->thumbnail ?? ''), 'premium' => (bool) $episode->parent_premium, 'ppv' => (bool) $episode->parent_ppv];
    }
}
