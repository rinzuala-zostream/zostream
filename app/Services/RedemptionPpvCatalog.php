<?php

namespace App\Services;

use App\Models\MovieModel;
use App\Models\New\Episode;
use App\Models\New\Season;
use App\Models\RedemptionCode;
use Illuminate\Validation\ValidationException;

class RedemptionPpvCatalog
{
    public function target(RedemptionCode $code): MovieModel|Season|Episode
    {
        $target = match ($code->content_type ?? 'movie') {
            'movie' => MovieModel::where('id', $code->movie_id)->where('status', 'Published')->where('isEnable', true)->where('isPayPerView', true)->first(),
            'season' => Season::with('movie')->where('id', $code->movie_id)->where('status', 'Published')->where('isPayPerView', true)
                ->whereHas('movie', fn ($q) => $q->where('status', 'Published')->where('isEnable', true))->first(),
            'episode' => Episode::with('season.movie')->where('id', $code->movie_id)->where('status', 'Published')->where('is_active', true)->where('isPayPerView', true)
                ->whereHas('season', fn ($q) => $q->where('status', 'Published')->whereHas('movie', fn ($movie) => $movie->where('status', 'Published')->where('isEnable', true)))->first(),
            default => null,
        };
        if (! $target) {
            throw ValidationException::withMessages(['movie_id' => 'This PPV movie, season or episode is no longer available.']);
        }

        return $target;
    }

    public function presentation(RedemptionCode $code, MovieModel|Season|Episode|null $target = null): array
    {
        // Reprinting history remains possible even after a title is unpublished.
        $type = $code->content_type ?? 'movie';
        $target ??= match ($type) {
            'season' => Season::with('movie')->where('id', $code->movie_id)->first(),
            'episode' => Episode::with('season.movie')->where('id', $code->movie_id)->first(),
            default => MovieModel::where('id', $code->movie_id)->first(),
        };
        $season = $target instanceof Episode ? $target->season : ($target instanceof Season ? $target : null);
        $movie = $target instanceof MovieModel ? $target : $season?->movie;
        $scope = match ($type) {
            'season' => 'Season '.($season?->season_number ?? '').' · Full season',
            'episode' => 'Season '.($season?->season_number ?? '').' · Episode '.($target?->episode_number ?? '').' only',
            default => 'Movie',
        };
        $title = $target?->title ?: ucfirst($type).' access';
        $scopeNumber = match ($type) {
            'season' => $season?->season_number,
            'episode' => $target instanceof Episode ? $target->episode_number : null,
            default => null,
        };
        $isRepeatedNumber = $scopeNumber !== null
            && preg_match('/^'.$type.'\s+([0-9]+)$/iD', trim($title), $number)
            && (int) $number[1] === (int) $scopeNumber;
        $poster = $type === 'movie' ? $movie?->poster : ($season?->poster ?: ($movie?->poster ?: $target?->thumbnail));

        return [
            'content_type' => $type, 'content_id' => $code->movie_id,
            'target_name' => $title, 'series_title' => $type === 'movie' ? null : $movie?->title,
            'scope_label' => $scope, 'poster_url' => $poster,
            'season_number' => $season?->season_number,
            'episode_number' => $target instanceof Episode ? $target->episode_number : null,
            'display_name' => $type === 'movie' ? $title : implode(' · ', array_filter([$movie?->title, $scope, $isRepeatedNumber ? null : $title])),
        ];
    }

    public function options(): array
    {
        $seasons = Season::with(['movie', 'episodes' => fn ($q) => $q->where('isPayPerView', true)->where('status', 'Published')->where('is_active', true)->orderBy('episode_number')])
            ->where('status', 'Published')
            ->whereHas('movie', fn ($q) => $q->where('status', 'Published')->where('isEnable', true))
            ->where(fn ($q) => $q->where('isPayPerView', true)->orWhereHas('episodes', fn ($ep) => $ep->where('isPayPerView', true)->where('status', 'Published')->where('is_active', true)))
            ->orderBy('season_number')->get();

        return [
            'movies' => MovieModel::where('status', 'Published')->where('isEnable', true)->where('isPayPerView', true)
                ->orderBy('title')->get(['id', 'title', 'poster']),
            'series' => $seasons->groupBy('movie_id')->map(function ($items) {
                $movie = $items->first()->movie;

                return [
                    'id' => $movie->id, 'title' => $movie->title, 'poster' => $movie->poster,
                    'seasons' => $items->map(fn ($season) => [
                        'id' => $season->id, 'title' => $season->title, 'season_number' => $season->season_number,
                        'poster' => $season->poster ?: $movie->poster, 'is_pay_per_view' => $season->isPayPerView,
                        'episodes' => $season->episodes->map(fn ($episode) => [
                            'id' => $episode->id, 'title' => $episode->title, 'episode_number' => $episode->episode_number,
                            'thumbnail' => $episode->thumbnail,
                        ])->values(),
                    ])->values(),
                ];
            })->sortBy('title')->values(),
        ];
    }
}
