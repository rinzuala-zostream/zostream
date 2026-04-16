<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Models\New\Season;
use App\Models\MovieModel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class SeasonController extends Controller
{
    // Search seasons by movie title
    public function searchByMovieTitle(Request $request)
    {
        try {
            $validated = $request->validate([
                'q' => 'required|string|min:2',
                'limit' => 'nullable|integer|min:1|max:50',
            ]);

            $query = trim($validated['q']);
            $limit = $validated['limit'] ?? 20;

            $movies = MovieModel::query()
                ->select(['num', 'id', 'title', 'poster', 'genre', 'status'])
                ->where('title', 'LIKE', "%{$query}%")
                ->whereHas('seasons')
                ->with([
                    'seasons' => function ($seasonQuery) {
                        $seasonQuery
                            ->orderBy('season_number')
                            ->with([
                                'episodes' => function ($episodeQuery) {
                                    $episodeQuery->orderBy('episode_number');
                                }
                            ]);
                    }
                ])
                ->orderBy('title')
                ->limit($limit)
                ->get();

            return response()->json([
                'status' => 'success',
                'query' => $query,
                'count' => $movies->count(),
                'data' => $movies
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Season search by movie title error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to search seasons: ' . $e->getMessage()
            ], 500);
        }
    }

    // Get all seasons of a movie
    // Get all seasons of a movie
    public function index(Request $request, $movieId)
    {
        try {
            $isAdmin = $request->hasHeader('admin');

            $seasonsQuery = Season::with([
                'episodes' => function ($q) use ($isAdmin) {
                    if (!$isAdmin) {
                        $q->where('status', 'Published');
                    }

                    $q->orderBy('episode_number');
                }
            ])
                ->where('movie_id', $movieId);

            if (!$isAdmin) {
                $seasonsQuery->where('status', 'Published');
            }

            $seasons = $seasonsQuery
                ->orderBy('season_number')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $seasons
            ]);

        } catch (\Exception $e) {
            Log::error('Season index error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch seasons: ' . $e->getMessage()
            ], 500);
        }
    }

    // Create season
    public function store(Request $request)
    {
        try {

            $validated = $request->validate([
                'movie_id' => 'required|integer',
                'season_number' => 'required|integer',
                'title' => 'nullable|string',
                'description' => 'nullable|string',
                'poster' => 'nullable|string',
                'release_date' => 'nullable|integer',
                'status' => 'nullable|string|in:Draft,Published,Scheduled'
            ]);

            $season = Season::create([
                'id' => (string) Str::uuid(),
                'movie_id' => $validated['movie_id'],
                'season_number' => $validated['season_number'],
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'] ?? null,
                'poster' => $validated['poster'] ?? null,
                'release_date' => $validated['release_date'] ?? null,
                'status' => $validated['status'] ?? null
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $season
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            Log::error('Season create error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create season' . $e->getMessage()
            ], 500);
        }
    }

    // Show season
    public function show($id)
    {
        try {

            $season = Season::where('id', $id)
                ->with('episodes')
                ->first();

            if (!$season) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Season not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $season
            ]);

        } catch (\Exception $e) {

            Log::error('Season show error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch season' . $e->getMessage()
            ], 500);
        }
    }

    // Update season
    public function update(Request $request, $id)
    {
        try {

            $season = Season::where('id', $id)->first();

            if (!$season) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Season not found'
                ], 404);
            }

            $season->update($request->only([
                'season_number',
                'title',
                'description',
                'poster',
                'release_date',
                'status'
            ]));

            return response()->json([
                'status' => 'success',
                'data' => $season
            ]);

        } catch (\Exception $e) {

            Log::error('Season update error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update season' . $e->getMessage()
            ], 500);
        }
    }

    // Delete season
    public function destroy($id)
    {
        try {

            $season = Season::where('id', $id)->first();

            if (!$season) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Season not found'
                ], 404);
            }

            $season->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Season deleted'
            ]);

        } catch (\Exception $e) {

            Log::error('Season delete error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete season' . $e->getMessage()
            ], 500);
        }
    }
}
