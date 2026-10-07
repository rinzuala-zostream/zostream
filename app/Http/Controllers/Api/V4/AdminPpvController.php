<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\MovieModel;
use App\Models\New\Episode;
use App\Models\New\PaymentHistory;
use App\Models\New\Season;
use Illuminate\Http\JsonResponse;
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
        $availableSeasons = Season::query()->where('isPayPerView', true)
            ->orderByDesc('num')->get(['num', 'movie_id', 'season_number', 'title', 'poster', 'amount']);
        $availableEpisodes = Episode::query()->where('isPayPerView', true)
            ->orderByDesc('num')->get(['num', 'season_id', 'episode_number', 'title', 'thumbnail', 'amount']);

        $purchases = (clone $successful)->orderByDesc('created_at')->limit(100)->get([
            'id', 'user_id', 'movie_id', 'amount', 'currency', 'device_type',
            'payment_gateway', 'transaction_id', 'payment_date', 'created_at', 'expiry_date',
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => [
                    'available_movies' => $availableMovies->count(),
                    'available_seasons' => $availableSeasons->count(),
                    'available_episodes' => $availableEpisodes->count(),
                    'purchased_count' => (clone $successful)->count(),
                    'total_amount' => round((float) (clone $successful)->sum('amount'), 2),
                    'currency_totals' => (clone $successful)->select('currency', DB::raw('SUM(amount) as total_amount'), DB::raw('COUNT(*) as purchase_count'))
                        ->groupBy('currency')->get(),
                ],
                'available' => [
                    'movies' => $availableMovies,
                    'seasons' => $availableSeasons,
                    'episodes' => $availableEpisodes,
                ],
                'purchases' => $purchases,
            ],
        ]);
    }
}
