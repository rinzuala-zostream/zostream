<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\MovieModel;
use App\Models\New\Episode;
use App\Models\New\PaymentHistory;
use App\Models\New\Season;
use App\Models\PPVPaymentModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPpvController extends Controller
{
    public function index(): JsonResponse
    {
        $successful = $this->newPpvPayments();
        $legacySuccessful = $this->legacyPpvPayments();

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
                'ppv_season_count' => $seasonsByMovie->get($movieId, collect())->where('isPayPerView', true)->count(),
                'ppv_episode_count' => $episodesByMovie->get($movieId, collect())->count(),
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
                    'purchased_count' => (clone $successful)->count() + (clone $legacySuccessful)->count(),
                    'total_amount' => round((float) (clone $successful)->sum('amount') + (float) (clone $legacySuccessful)->sum('amount_paid'), 2),
                    'currency_totals' => $this->currencyTotals($successful, $legacySuccessful),
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
        $contentIds = collect(explode(',', (string) $request->query('content_ids', $request->query('content_id', ''))))
            ->map(fn ($id) => trim($id))->filter()->unique()->values();
        $new = $this->newPpvPayments();
        $legacy = $this->legacyPpvPayments();
        if ($contentIds->isNotEmpty()) {
            $new->whereIn('movie_id', $contentIds);
            $legacy->whereIn('movie_id', $contentIds);
        }
        $summary = [
            'purchase_count' => (clone $new)->count() + (clone $legacy)->count(),
            'total_amount' => round((float) (clone $new)->sum('amount') + (float) (clone $legacy)->sum('amount_paid'), 2),
            'currency_totals' => $this->currencyTotals($new, $legacy),
        ];
        $new->select(['id', 'user_id', 'movie_id', 'amount', 'currency', 'device_type', 'payment_gateway', 'transaction_id', 'payment_date', 'created_at', 'expiry_date'])
            ->selectRaw("'current' as source");
        $legacyCurrency = DB::getSchemaBuilder()->hasColumn('ppv_payment', 'currency') ? 'currency' : DB::raw("'INR' as currency");
        $legacy->select(['id', 'user_id', 'movie_id'])
            ->selectRaw('amount_paid as amount')
            ->addSelect($legacyCurrency)
            ->selectRaw('platform as device_type')
            ->selectRaw((DB::getSchemaBuilder()->hasColumn('ppv_payment', 'pg') ? 'pg' : 'NULL').' as payment_gateway')
            ->selectRaw('payment_id as transaction_id')
            ->selectRaw('purchase_date as payment_date')
            ->selectRaw('created_at')
            ->selectRaw('NULL as expiry_date')
            ->selectRaw("'legacy' as source");
        $union = $new->unionAll($legacy);
        $purchases = DB::query()->fromSub($union, 'ppv_purchases')->orderByDesc('created_at')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return response()->json(['status' => 'success', 'data' => $purchases, 'summary' => $summary]);
    }

    public function show(string $type, string $id): JsonResponse
    {
        $item = match ($type) {
            'movie' => MovieModel::query()->where('num', $id)->orWhere('id', $id)->first(),
            'parent' => MovieModel::query()->with(['seasons' => fn ($query) => $query->where(function ($seasonQuery): void {
                $seasonQuery->where('isPayPerView', true)->orWhereHas('episodes', fn ($episodes) => $episodes->where('isPayPerView', true));
            }), 'seasons.episodes' => fn ($episodes) => $episodes->where('isPayPerView', true)->orderBy('episode_number')])->where('num', $id)->orWhere('id', $id)->first(),
            'season' => Season::query()->with(['movie:num,id,title,poster', 'episodes' => fn ($query) => $query->where('isPayPerView', true)->orderBy('episode_number')])->where('id', $id)->orWhere('num', $id)->first(),
            'episode' => Episode::query()->with('season.movie:num,id,title,poster')->where('id', $id)->orWhere('num', $id)->first(),
            default => null,
        };

        if (! $item) {
            return response()->json(['status' => 'error', 'message' => 'PPV content not found.'], 404);
        }

        $purchaseIds = match ($type) {
            'movie' => [$item->num, $item->id],
            'parent' => collect([$item->num, $item->id])->merge($item->seasons->flatMap(fn ($season) => [
                $season->num, $season->id, $season->movie_id,
                ...$season->episodes->flatMap(fn ($episode) => [$episode->num, $episode->id, $season->movie_id.'_'.$episode->id])->all(),
            ]))->all(),
            'season' => [$item->num, $item->id, $item->movie_id],
            'episode' => [$item->num, $item->id, $item->season_id, $item->season?->movie_id.'_'.$item->id],
            default => [],
        };
        if ($type === 'season') {
            $item->setAttribute('ppv_episode_count', $item->episodes->count());
        }

        if ($type === 'parent') {
            $item->seasons->each(function (Season $season): void {
                $season->setAttribute('ppv_episode_count', $season->episodes->count());
            });
            $item->setAttribute('ppv_season_count', $item->seasons->where('isPayPerView', true)->count());
            $item->setAttribute('ppv_episode_count', $item->seasons->sum(fn (Season $season) => $season->episodes->count()));
        }

        return response()->json(['status' => 'success', 'data' => [
            'type' => $type,
            'item' => $item,
            'purchase_content_ids' => collect($purchaseIds)->filter()->unique()->values(),
        ]]);
    }

    private function newPpvPayments()
    {
        return PaymentHistory::query()->where('app_payment_type', 'ppv')->whereRaw('LOWER(status) = ?', ['success']);
    }

    private function legacyPpvPayments()
    {
        return PPVPaymentModel::query()->whereIn(DB::raw('LOWER(payment_status)'), ['success', 'captured', 'completed']);
    }

    private function currencyTotals($new, $legacy): array
    {
        $totals = (clone $new)->select('currency', DB::raw('SUM(amount) as total_amount'), DB::raw('COUNT(*) as purchase_count'))->groupBy('currency')->get()
            ->mapWithKeys(fn ($row) => [strtoupper($row->currency ?: 'INR') => ['currency' => strtoupper($row->currency ?: 'INR'), 'total_amount' => (float) $row->total_amount, 'purchase_count' => (int) $row->purchase_count]]);
        $legacyTotal = (float) (clone $legacy)->sum('amount_paid');
        $legacyCount = (int) (clone $legacy)->count();
        if ($legacyCount > 0) {
            $currency = DB::getSchemaBuilder()->hasColumn('ppv_payment', 'currency') ? 'currency' : null;
            if ($currency) {
                foreach ((clone $legacy)->select('currency', DB::raw('SUM(amount_paid) as total_amount'), DB::raw('COUNT(*) as purchase_count'))->groupBy('currency')->get() as $row) {
                    $key = strtoupper($row->currency ?: 'INR');
                    $current = $totals->get($key, ['currency' => $key, 'total_amount' => 0, 'purchase_count' => 0]);
                    $current['total_amount'] += (float) $row->total_amount;
                    $current['purchase_count'] += (int) $row->purchase_count;
                    $totals->put($key, $current);
                }
            } else {
                $current = $totals->get('INR', ['currency' => 'INR', 'total_amount' => 0, 'purchase_count' => 0]);
                $current['total_amount'] += $legacyTotal;
                $current['purchase_count'] += $legacyCount;
                $totals->put('INR', $current);
            }
        }
        return $totals->values()->all();
    }
}
