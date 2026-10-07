<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\MovieModel;
use App\Models\New\Episode;
use App\Models\New\PaymentHistory;
use App\Models\New\Season;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPpvController extends Controller
{
    public function index(): JsonResponse
    {
        $successful = PaymentHistory::query()
            ->where('app_payment_type', 'ppv')
            ->whereRaw('LOWER(status) = ?', ['success']);

        $availableMovies = MovieModel::query()->where('isPayPerView', true)
            ->orderByDesc('num')->get(['num', 'title', 'poster', 'ppv_amount']);
        $availableSeasons = Season::query()->where(function ($query): void {
            $query->where('isPayPerView', true)->orWhereHas('episodes', fn ($episodes) => $episodes->where('isPayPerView', true));
        })->orderByDesc('num')->get(['num', 'id', 'movie_id', 'season_number', 'title', 'poster', 'amount', 'isPayPerView']);
        $availableEpisodes = Episode::query()->where('isPayPerView', true)
            ->with('season:id,movie_id,season_number,title')
            ->orderByDesc('num')->get(['num', 'id', 'season_id', 'episode_number', 'title', 'thumbnail', 'amount']);
        $availableSeasons->each(function (Season $season) use ($availableEpisodes): void {
            $season->setAttribute('ppv_episode_count', $availableEpisodes->where('season_id', $season->id)->count());
        });

        $seasonsByMovie = $availableSeasons->groupBy('movie_id');
        $episodesByMovie = $availableEpisodes->groupBy(fn (Episode $episode) => $episode->season?->movie_id);
        $parentIds = $seasonsByMovie->keys()->merge($episodesByMovie->keys())->filter()->unique()->values();
        $parents = MovieModel::query()->whereIn('num', $parentIds)->get(['num', 'id', 'title', 'poster'])->keyBy('num');
        $series = $parentIds->map(function ($movieId) use ($parents, $seasonsByMovie, $episodesByMovie) {
            $parent = $parents->get($movieId);
            if (! $parent) {
                return null;
            }
            return [
                'movie_id' => (int) $movieId,
                'title' => $parent->title,
                'poster' => $parent->poster,
                'ppv_seasons' => $seasonsByMovie->get($movieId, collect())->values(),
                'ppv_episodes' => $episodesByMovie->get($movieId, collect())->values(),
            ];
        })->filter()->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => [
                    'available_movies' => $availableMovies->count(),
                    'available_seasons' => $availableSeasons->where('isPayPerView', true)->count(),
                    'available_episodes' => $availableEpisodes->count(),
                    'purchased_count' => (clone $successful)->count(),
                    'total_amount' => round((float) (clone $successful)->sum('amount'), 2),
                    'currency_totals' => (clone $successful)->select('currency', DB::raw('SUM(amount) as total_amount'), DB::raw('COUNT(*) as purchase_count'))
                        ->groupBy('currency')->get(),
                ],
                'available' => [
                    'movies' => $availableMovies,
                    'series' => $series,
                ],
            ],
        ]);
    }

    public function purchases(Request $request): JsonResponse
    {
        $query = PaymentHistory::query()
            ->where('app_payment_type', 'ppv')
            ->whereRaw('LOWER(status) = ?', ['success']);

        $contentIds = collect(explode(',', (string) $request->query('content_ids', $request->query('content_id', ''))))
            ->map(fn ($id) => trim($id))->filter()->unique()->values();
        if ($contentIds->isNotEmpty()) {
            $query->whereIn('movie_id', $contentIds);
        }

        $purchases = $query->orderByDesc('created_at')->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return response()->json(['status' => 'success', 'data' => $purchases]);
    }

    public function show(string $type, string $id): JsonResponse
    {
        $item = match ($type) {
            'movie' => MovieModel::query()->where('num', $id)->orWhere('id', $id)->first(),
            'season' => Season::query()->with(['movie:num,id,title,poster', 'episodes' => fn ($query) => $query->where('isPayPerView', true)->orderBy('episode_number')])->where('id', $id)->orWhere('num', $id)->first(),
            'episode' => Episode::query()->with('season.movie:num,id,title,poster')->where('id', $id)->orWhere('num', $id)->first(),
            default => null,
        };

        if (! $item) {
            return response()->json(['status' => 'error', 'message' => 'PPV content not found.'], 404);
        }

        $purchaseIds = match ($type) {
            'movie' => [$item->num, $item->id],
            'season' => [$item->num, $item->id, $item->movie_id],
            'episode' => [$item->num, $item->id, $item->season_id, $item->season?->movie_id.'_'.$item->id],
            default => [],
        };
        if ($type === 'season') {
            $item->setAttribute('ppv_episode_count', $item->episodes->count());
        }

        return response()->json(['status' => 'success', 'data' => [
            'type' => $type,
            'item' => $item,
            'purchase_content_ids' => collect($purchaseIds)->filter()->unique()->values(),
        ]]);
    }
}
