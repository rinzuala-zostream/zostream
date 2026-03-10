<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Models\New\Season;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class SeasonController extends Controller
{

    // Get all seasons of a movie
    public function index($movieId)
    {
        try {

            $seasons = Season::where('movie_id', $movieId)
                ->orderBy('season_number')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $seasons
            ]);

        } catch (\Exception $e) {

            Log::error('Season index error: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch seasons' . $e->getMessage()
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
                'release_year' => 'nullable|integer'
            ]);

            $season = Season::create([
                'id' => Str::random(20),
                'movie_id' => $validated['movie_id'],
                'season_number' => $validated['season_number'],
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'] ?? null,
                'poster' => $validated['poster'] ?? null,
                'release_year' => $validated['release_year'] ?? null,
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

            Log::error('Season create error: '.$e->getMessage());

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

            Log::error('Season show error: '.$e->getMessage());

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
                'release_year'
            ]));

            return response()->json([
                'status' => 'success',
                'data' => $season
            ]);

        } catch (\Exception $e) {

            Log::error('Season update error: '.$e->getMessage());

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

            Log::error('Season delete error: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete season' . $e->getMessage()
            ], 500);
        }
    }
}