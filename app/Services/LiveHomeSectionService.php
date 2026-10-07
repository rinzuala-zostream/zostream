<?php

namespace App\Services;

use App\Models\MovieModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class LiveHomeSectionService
{
    private const LIVE_SECTIONS = [
        'latest_update',
        'continue_watching',
        'trending_now',
        'last_month_top_10',
        'new_releases',
        'your_wishlist',
        'next_episode',
        'ppv_seasons',
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
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->limit(1000)
                ->get(['movie_id', 'movie_type', 'position', 'duration', 'created_at', 'updated_at'])
                ->map(fn ($row) => (array) $row)->all()
            : [];
        $wishlist = $needsWishlist
            ? DB::table('wist_list')
                ->where('uid', $userId)
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->limit(max($fetchLimit, 200))
                ->get(['movie_id', 'created_at', 'updated_at'])
                ->map(fn ($row) => (array) $row)->all()
            : [];

        $sections = [];
        if (in_array('latest_update', $requested, true)) {
            $sections['latest_update'] = $this->rememberLiveSection(
                sprintf(
                    'latest-update:%s:%d:%s:%d',
                    $userId === 'AW7ovVnTdgWuvE1Uke7QTQ5OEQt1' ? 'mizo-only' : 'all',
                    $fetchLimit,
                    $mode,
                    (int) $includeAgeRestricted
                ),
                fn (): array => $this->latestUpdates(
                    $userId,
                    $fetchLimit,
                    $mode,
                    $includeAgeRestricted
                )
            );
        }
        if (in_array('continue_watching', $requested, true)) {
            $sections['continue_watching'] = $this->continueWatching($watch, $fetchLimit, $mode, $includeAgeRestricted);
        }
        if (in_array('trending_now', $requested, true)) {
            $sections['trending_now'] = $this->rememberLiveSection(
                sprintf('trending-now:%d:%s:%d', $fetchLimit, $mode, (int) $includeAgeRestricted),
                fn (): array => $this->movies(
                    $this->allowedMovies($mode, $includeAgeRestricted)->orderByDesc('views')->orderByDesc('num')->limit($fetchLimit)->get(self::MOVIE_CARD_COLUMNS)
                )
            );
        }
        if (in_array('last_month_top_10', $requested, true)) {
            try {
                // Never aggregate watch history on the request path, even on
                // a cold cache. The scheduled warmer publishes this shelf.
                $sections['last_month_top_10'] = Cache::get(
                    $this->lastMonthCacheKey($mode, $includeAgeRestricted),
                    []
                );
            } catch (Throwable $exception) {
                // An optional ranking shelf must never make the complete home
                // recommendation response fail and trigger a catalog fallback.
                Log::warning('Last-month Top 10 section could not be built.', [
                    'exception' => $exception,
                ]);
                $sections['last_month_top_10'] = [];
            }
        }
        if (in_array('new_releases', $requested, true)) {
            $sections['new_releases'] = $this->rememberLiveSection(
                sprintf('new-releases:%d:%s:%d', $fetchLimit, $mode, (int) $includeAgeRestricted),
                fn (): array => $this->movies(
                    $this->allowedMovies($mode, $includeAgeRestricted)->whereNotNull('release_on')->orderByDesc('release_on')->orderByDesc('num')->limit($fetchLimit)->get(self::MOVIE_CARD_COLUMNS)
                )
            );
        }
        if (in_array('your_wishlist', $requested, true)) {
            $sections['your_wishlist'] = $this->wishlist($wishlist, $fetchLimit, $mode, $includeAgeRestricted);
        }
        if (in_array('next_episode', $requested, true)) {
            $sections['next_episode'] = $this->nextEpisodes($watch, $fetchLimit, $mode, $includeAgeRestricted);
        }
        if (in_array('ppv_seasons', $requested, true)) {
            $sections['ppv_seasons'] = $this->ppvSeasons($fetchLimit, $mode, $includeAgeRestricted);
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

    private function rememberLiveSection(string $key, callable $callback): array
    {
        $seconds = max(0, (int) config('recommender.live_section_cache_seconds', 60));
        if ($seconds === 0) {
            return $callback();
        }

        return Cache::remember(
            'recommendations:live-section:'.$key,
            now()->addSeconds($seconds),
            $callback
        );
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
            ->get(['id', 'poster', 'cover_img', 'isPremium', 'isPayPerView'])
            ->keyBy(fn ($movie) => (string) $movie->id);

        foreach (['because_you_watched', 'top_picks_for_you', 'similar_movies'] as $key) {
            if (! isset($homepage[$key])) {
                continue;
            }
            if (isset($homepage[$key]['items'])) {
                $homepage[$key]['items'] = $this->hydrateAiCards(
                    $homepage[$key]['items'],
                    $allowed
                );
                if (isset($homepage[$key]['anchor']['id'])) {
                    $anchor = $allowed->get((string) $homepage[$key]['anchor']['id']);
                    if ($anchor) {
                        $homepage[$key]['anchor'] = $this->hydrateAiCard(
                            $homepage[$key]['anchor'],
                            $anchor
                        );
                    } else {
                        $homepage[$key]['anchor'] = null;
                    }
                }
            } else {
                $homepage[$key] = $this->hydrateAiCards($homepage[$key], $allowed);
            }
        }

        return $homepage;
    }

    private function hydrateAiCards(array $items, $allowed): array
    {
        $cards = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $movie = $allowed->get((string) ($item['id'] ?? ''));
            if (! $movie) {
                continue;
            }
            $cards[] = $this->hydrateAiCard($item, $movie);
        }

        return $cards;
    }

    private function hydrateAiCard(array $item, $movie): array
    {
        // AI model artifacts historically contain poster only. The API card must
        // carry the database cover image so every client renders the landscape cover.
        $item['cover_img'] = (string) ($movie->cover_img ?: $movie->poster ?: '');
        $item['poster'] = (string) ($movie->poster ?? '');
        $item['premium'] = (bool) $movie->isPremium;
        $item['ppv'] = (bool) $movie->isPayPerView;

        return $item;
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

    private function lastMonthCacheKey(string $mode, bool $includeAgeRestricted, ?string $month = null): string
    {
        return sprintf(
            'home:last-month-top-10:%s:%s:%d',
            $month ?? now()->subMonthNoOverflow()->format('Y-m'),
            $mode,
            (int) $includeAgeRestricted
        );
    }

    /** Refresh all audiences off the request path, scanning history only once per type. */
    public function warmLastMonthTopTen(): void
    {
        $monthEnd = now()->startOfMonth();
        $monthStart = $monthEnd->copy()->subMonth();
        // Capture keys before querying so a refresh crossing midnight cannot
        // publish the previous month's ranking under the new month's key.
        $audiences = [];
        foreach (['adult', 'kids'] as $mode) {
            foreach ([false, true] as $includeAgeRestricted) {
                $audiences[] = [$mode, $includeAgeRestricted, $this->lastMonthCacheKey($mode, $includeAgeRestricted, $monthStart->format('Y-m'))];
            }
        }

        $movieCounts = DB::table('watch_position')
            ->where('updated_at', '>=', $monthStart)
            ->where('updated_at', '<', $monthEnd)
            ->whereRaw("LOWER(COALESCE(movie_type, '')) <> 'episode'")
            ->groupBy('movie_id')
            ->select('movie_id', DB::raw('COUNT(*) as monthly_views'))
            ->get();

        $episodeCounts = DB::table('watch_position')
            ->join('episodes', 'episodes.id', '=', 'watch_position.movie_id')
            ->join('seasons', 'seasons.id', '=', 'episodes.season_id')
            ->where('watch_position.updated_at', '>=', $monthStart)
            ->where('watch_position.updated_at', '<', $monthEnd)
            ->whereRaw("LOWER(COALESCE(watch_position.movie_type, '')) = 'episode'")
            ->groupBy('seasons.movie_id')
            ->select('seasons.movie_id', DB::raw('COUNT(*) as monthly_views'))
            ->get();

        foreach ($audiences as [$mode, $includeAgeRestricted, $key]) {
            $cards = $this->lastMonthTopTen($movieCounts, $episodeCounts, $mode, $includeAgeRestricted);
            // Preserve the last successful result during transient refresh
            // failures. Month-specific keys prevent displaying the wrong month.
            Cache::put($key, $cards, now()->addDays(35));
        }
    }

    /** Roll episode activity up to its parent series and apply audience filters. */
    private function lastMonthTopTen($movieCounts, $episodeCounts, string $mode, bool $includeAgeRestricted): array
    {
        $scores = [];
        $movies = $this->allowedMovies($mode, $includeAgeRestricted)
            ->whereIn('id', $movieCounts->pluck('movie_id'))
            ->get(self::MOVIE_CARD_COLUMNS);
        foreach ($movies as $movie) {
            $scores[(string) $movie->id] = [
                'movie' => $movie,
                'views' => 0,
            ];
        }
        foreach ($movieCounts as $count) {
            $id = (string) $count->movie_id;
            if (isset($scores[$id])) {
                $scores[$id]['views'] += (int) $count->monthly_views;
            }
        }

        $series = $this->allowedMovies($mode, $includeAgeRestricted)
            ->whereIn('num', $episodeCounts->pluck('movie_id'))
            ->get(array_merge(self::MOVIE_CARD_COLUMNS, ['num']));
        $seriesByNumber = $series->keyBy(fn ($movie) => (string) $movie->num);
        foreach ($episodeCounts as $count) {
            $movie = $seriesByNumber->get((string) $count->movie_id);
            if (! $movie) {
                continue;
            }
            $id = (string) $movie->id;
            $scores[$id] ??= ['movie' => $movie, 'views' => 0];
            $scores[$id]['views'] += (int) $count->monthly_views;
        }

        usort($scores, function (array $left, array $right): int {
            $byViews = $right['views'] <=> $left['views'];

            return $byViews !== 0
                ? $byViews
                : strcmp((string) $left['movie']->title, (string) $right['movie']->title);
        });

        return array_map(function (array $ranked): array {
            return $this->movieCard($ranked['movie']) + [
                'monthly_views' => $ranked['views'],
            ];
        }, array_slice($scores, 0, 10));
    }

    /**
     * Keep this shelf aligned with the legacy Home API's "Latest Update"
     * category: newest update first, with creation date and movie number as
     * fallbacks. It is deliberately live data rather than an AI result.
     */
    private function latestUpdates(string $userId, int $limit, string $mode, bool $includeAgeRestricted): array
    {
        $movies = $this->allowedMovies($mode, $includeAgeRestricted)
            // The legacy Home endpoint serves Mizo-only titles for this
            // legacy account, so preserve that established behaviour here.
            ->when($userId === 'AW7ovVnTdgWuvE1Uke7QTQ5OEQt1', fn (Builder $query) => $query->where('isMizo', 1))
            ->orderByRaw('COALESCE(movie.updated_at, movie.create_date) DESC, movie.num DESC')
            ->limit($limit)
            ->get(self::MOVIE_CARD_COLUMNS);

        return $this->movies($movies);
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

    private function ppvSeasons(int $limit, string $mode, bool $includeAgeRestricted): array
    {
        return DB::table('seasons')
            ->join('movie', 'movie.num', '=', 'seasons.movie_id')
            ->leftJoin('episodes', 'episodes.season_id', '=', 'seasons.id')
            ->where('movie.status', 'Published')
            ->where('movie.isEnable', 1)
            ->where('seasons.status', 'Published')
            ->when($mode === 'kids', fn ($query) => $query->where('movie.isChildMode', 1))
            ->when($mode === 'kids' || ! $includeAgeRestricted, fn ($query) => $query->where('movie.isAgeRestricted', 0))
            ->groupBy(
                'seasons.id', 'seasons.movie_id', 'seasons.season_number', 'seasons.title',
                'seasons.isPayPerView', 'movie.id', 'movie.title', 'movie.poster',
                'movie.cover_img', 'movie.isPremium', 'movie.isPayPerView'
            )
            ->havingRaw('MAX(CASE WHEN seasons.isPayPerView = 1 OR episodes.isPayPerView = 1 THEN 1 ELSE 0 END) = 1')
            ->orderBy('movie.title')
            ->orderBy('seasons.season_number')
            ->limit($limit)
            ->get([
                'seasons.id as season_id', 'seasons.movie_id', 'seasons.season_number',
                'seasons.title as season_title', 'seasons.isPayPerView as season_ppv',
                'movie.id as parent_id', 'movie.title as series_title', 'movie.poster',
                'movie.cover_img', 'movie.isPremium as premium', 'movie.isPayPerView as parent_ppv',
                DB::raw('SUM(CASE WHEN episodes.isPayPerView = 1 THEN 1 ELSE 0 END) as ppv_episode_count'),
            ])
            ->map(fn ($season): array => [
                'id' => (string) $season->season_id,
                'type' => 'season',
                'parent_id' => (string) $season->parent_id,
                'series_title' => (string) $season->series_title,
                'title' => (string) ($season->season_title ?: 'Season '.$season->season_number),
                'season_number' => (int) $season->season_number,
                'poster' => (string) ($season->poster ?? ''),
                'cover_img' => (string) ($season->cover_img ?: $season->poster ?: ''),
                'premium' => (bool) $season->premium,
                'ppv' => (bool) $season->season_ppv,
                'isPayPerView' => (bool) $season->season_ppv,
                'ppv_episode_count' => (int) $season->ppv_episode_count,
            ])->values()->all();
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
