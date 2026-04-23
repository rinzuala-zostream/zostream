<?php

namespace App\Http\Controllers;

use App\Models\EpisodeModel;
use App\Models\MovieModel;
use App\Models\WatchHistoryModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class WatchPositionController extends Controller
{
    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key');
    }

    public function save(Request $request)
    {
    
        // Validate input
        $request->validate([
            'movie_id' => 'required|string',
            'position' => 'required|integer',
            'user_id' => 'required|string',
            'movie_type' => 'nullable|string',
        ]);

        try {

            $movieId = $request->input('movie_id');
            $position = $request->input('position');
            $userId = $request->input('user_id');
            $movieType = $request->input('movie_type');
            $now = now();
            $hasCreatedAt = Schema::hasColumn('watch_position', 'created_at');
            $hasUpdatedAt = Schema::hasColumn('watch_position', 'updated_at');

            // Check if the record exists
            $existing = WatchHistoryModel::where('movie_id', $movieId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // Update existing
                $payload = [
                    'position' => $position,
                    'movie_type' => $movieType,
                ];

                if ($hasUpdatedAt) {
                    $payload['updated_at'] = $now;
                }

                WatchHistoryModel::where('movie_id', $movieId)
                    ->where('user_id', $userId)
                    ->update($payload);
            } else {
                // Insert new
                $payload = [
                    'movie_id' => $movieId,
                    'position' => $position,
                    'user_id' => $userId,
                    'movie_type' => $movieType,
                ];

                if ($hasCreatedAt) {
                    $payload['created_at'] = $now;
                }

                if ($hasUpdatedAt) {
                    $payload['updated_at'] = $now;
                }

                WatchHistoryModel::insert($payload);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Record saved successfully',
                'data' => [
                    'movie_id' => $movieId,
                    'position' => $position,
                    'user_id' => $userId,
                    'movie_type' => $movieType,
                ],
            ]);

        } catch (\Exception $e) {

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function getWatchContinue(Request $request)
    {
        $request->validate([
            'userId' => 'required|string',
            'isAgeRestricted' => 'nullable|string|in:true,false',
        ]);

        $userId = $request->query('userId');
        $includeAgeRestricted = ($request->query('isAgeRestricted') ?? 'false') === 'true';

        if (!$userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing userId'
            ], 400);
        }

        $hasUpdatedAt = Schema::hasColumn('watch_position', 'updated_at');
        $hasCreatedAt = Schema::hasColumn('watch_position', 'created_at');
        $orderColumn = $hasUpdatedAt ? 'updated_at' : ($hasCreatedAt ? 'created_at' : 'num');

        $watchData = WatchHistoryModel::where('user_id', $userId)
            ->where('position', '>', 0)
            ->orderByDesc($orderColumn)
            ->get();

        $movieIds = $watchData
            ->where('movie_type', '!=', 'episode')
            ->pluck('movie_id')
            ->filter()
            ->unique()
            ->values();

        $episodeIds = $watchData
            ->where('movie_type', 'episode')
            ->pluck('movie_id')
            ->filter()
            ->unique()
            ->values();

        $episodes = EpisodeModel::whereIn('id', $episodeIds)
            ->get()
            ->keyBy('id');

        $allMovieIds = $movieIds
            ->merge($episodes->pluck('movie_id'))
            ->filter()
            ->unique()
            ->values();

        $movies = MovieModel::whereIn('id', $allMovieIds)
            ->get()
            ->keyBy('id');

        $result = [];

        foreach ($watchData as $history) {
            $isEpisode = $history->movie_type === 'episode';
            $episode = $isEpisode ? $episodes->get($history->movie_id) : null;
            $movie = $isEpisode
                ? $movies->get($episode->movie_id ?? null)
                : $movies->get($history->movie_id);

            // Skip if movie not found or is age restricted
            if (!$movie || (!$includeAgeRestricted && (int) ($movie->isAgeRestricted ?? 0) === 1)) {
                continue;
            }

            $watchedAt = $hasUpdatedAt
                ? $history->updated_at
                : ($hasCreatedAt ? $history->created_at : null);

            $result[] = [
                'id' => $history->num,
                'movie_id' => $isEpisode ? ($episode->movie_id ?? $history->movie_id) : $history->movie_id,
                'episode_id' => $isEpisode ? $history->movie_id : null,
                'movie_type' => $history->movie_type,
                'position' => $history->position,
                'watched_at' => $watchedAt,
                'updated_at' => $hasUpdatedAt ? $history->updated_at : null,
                'created_at' => $hasCreatedAt ? $history->created_at : null,
                'movie' => $this->transformMovie($movie),
                'episode' => $isEpisode && $episode ? $this->transformEpisode($episode) : null,
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => $result,
            'watch_history' => $result,
        ]);
    }

    public function getWatchPosition(Request $request)
    {
    
        $request->validate([
            'userId' => 'required|string',
            'movieId' => 'required|string',
            'isAgeRestricted' => 'required|string|in:true,false',
        ]);

        $userId = $request->query('userId');
        $movieId = $request->query('movieId');

        if (!$userId || !$movieId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing userId or movieId'
            ], 400);
        }

        // Get watch position
        $watchData = WatchHistoryModel::select('position')
            ->where('user_id', $userId)
            ->where('movie_id', $movieId)
            ->first();

        $watchPosition = $watchData->position ?? 0;

        return response()->json([
            'status' => 'success',
            'watchPosition' => $watchPosition
        ]);
    }

    private function transformMovie(MovieModel $movie)
    {
        foreach ([
            'isProtected',
            'isBollywood',
            'isCompleted',
            'isDocumentary',
            'isDubbed',
            'isEnable',
            'isHollywood',
            'isKorean',
            'isMizo',
            'isPayPerView',
            'isPremium',
            'isAgeRestricted',
            'isSeason',
            'isSubtitle',
            'isChildMode',
        ] as $key) {
            if (isset($movie->$key)) {
                $movie->$key = (bool) $movie->$key;
            }
        }

        unset(
            $movie->url,
            $movie->dash_url,
            $movie->hls_url,
            $movie->token
        );

        return $movie;
    }

    private function transformEpisode(EpisodeModel $episode)
    {
        foreach ([
            'isProtected',
            'isPPV',
            'isPremium',
            'isEnable',
        ] as $key) {
            if (isset($episode->$key)) {
                $episode->$key = (bool) $episode->$key;
            }
        }

        unset(
            $episode->url,
            $episode->dash_url,
            $episode->hls_url,
            $episode->token
        );

        return $episode;
    }
}
