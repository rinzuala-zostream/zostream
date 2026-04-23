<?php

namespace App\Http\Controllers;

use App\Models\MovieModel;
use App\Models\New\Episode;
use App\Models\WatchHistoryModel;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $userId = $request->query('userId');
        $includeAgeRestricted = ($request->query('isAgeRestricted') ?? 'false') === 'true';
        $page = max((int) $request->query('page', 1), 1);
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);

        if (!$userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing userId'
            ], 400);
        }

        $result = $this->buildWatchContinueResult($userId, $includeAgeRestricted);

        $resultCollection = collect($result);
        $paginator = new LengthAwarePaginator(
            $resultCollection->forPage($page, $perPage)->values(),
            $resultCollection->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return response()->json([
            'status' => 'success',
            'data' => $paginator->items(),
            'watch_history' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'has_more_pages' => $paginator->hasMorePages(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
            ],
        ]);
    }

    private function buildWatchContinueResult($userId, $includeAgeRestricted)
    {
        $hasUpdatedAt = Schema::hasColumn('watch_position', 'updated_at');
        $hasCreatedAt = Schema::hasColumn('watch_position', 'created_at');
        $orderColumn = $hasUpdatedAt ? 'updated_at' : ($hasCreatedAt ? 'created_at' : 'num');

        $watchData = WatchHistoryModel::where('user_id', $userId)
            ->where('position', '>', 0)
            ->orderByDesc($orderColumn)
            ->get();

        $movieRows = $watchData->filter(fn ($item) => $this->normalizeMovieType($item->movie_type) !== 'episode');
        $episodeRows = $watchData->filter(fn ($item) => $this->normalizeMovieType($item->movie_type) === 'episode');

        $movieIds = $movieRows->pluck('movie_id')->filter()->unique()->values();
        $episodeIds = $episodeRows->pluck('movie_id')->filter()->unique()->values();

        $episodes = Episode::with('season')->whereIn('id', $episodeIds)->get()->keyBy('id');

        $allMovieIds = $movieIds->merge($episodes->pluck('season.movie_id'))->filter()->unique()->values();

        $movies = MovieModel::where(function ($query) use ($allMovieIds) {
                $query->whereIn('id', $allMovieIds)->orWhereIn('num', $allMovieIds);
            })->get();

        $moviesByKey = $this->buildMovieKeyMap($movies);

        return $this->processWatchHistory($watchData, $episodes, $moviesByKey, $hasUpdatedAt, $hasCreatedAt, $includeAgeRestricted);
    }

    private function buildMovieKeyMap($movies)
    {
        $moviesByKey = collect();
        foreach ($movies as $movie) {
            if (!empty($movie->id)) {
                $moviesByKey->put((string) $movie->id, $movie);
            }
            if (isset($movie->num)) {
                $moviesByKey->put((string) $movie->num, $movie);
            }
        }
        return $moviesByKey;
    }

    private function processWatchHistory($watchData, $episodes, $moviesByKey, $hasUpdatedAt, $hasCreatedAt, $includeAgeRestricted)
    {
        $result = [];

        foreach ($watchData as $history) {
            $normalizedMovieType = $this->normalizeMovieType($history->movie_type);
            $isEpisode = $normalizedMovieType === 'episode';
            $episode = $isEpisode ? $episodes->get($history->movie_id) : null;
            $parentMovieId = $isEpisode ? ($episode?->season?->movie_id) : $history->movie_id;
            $movie = $isEpisode ? $moviesByKey->get((string) $parentMovieId) : $moviesByKey->get((string) $history->movie_id);

            if ($isEpisode && !$episode) {
                continue;
            }

            if (!$movie || (!$includeAgeRestricted && (int) ($movie->isAgeRestricted ?? 0) === 1)) {
                continue;
            }

            $watchedAt = $hasUpdatedAt ? $history->updated_at : ($hasCreatedAt ? $history->created_at : null);

            $result[] = [
                'id' => $history->num,
                'movie_id' => $parentMovieId ?? $history->movie_id,
                'episode_id' => $isEpisode ? $history->movie_id : null,
                'movie_type' => $normalizedMovieType ?: $history->movie_type,
                'position' => $history->position,
                'watched_at' => $watchedAt,
                'updated_at' => $hasUpdatedAt ? $history->updated_at : null,
                'created_at' => $hasCreatedAt ? $history->created_at : null,
                'movie' => $this->transformMovie($movie),
                'episode' => $isEpisode && $episode ? $this->transformEpisode($episode) : null,
            ];
        }

        return $result;
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

    private function transformEpisode(Episode $episode)
    {
        foreach ([
            'isPayPerView',
            'isPremium',
            'is_active',
        ] as $key) {
            if (isset($episode->$key)) {
                $episode->$key = (bool) $episode->$key;
            }
        }

        unset($episode->season);

        return $episode;
    }

    private function normalizeMovieType($movieType): ?string
    {
        if ($movieType === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $movieType));

        return $normalized !== '' ? $normalized : null;
    }
}
