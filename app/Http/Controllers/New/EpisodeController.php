<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Models\New\Episode;
use App\Models\New\Season;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class EpisodeController extends Controller
{
    // Get all episodes of a season
    public function index($seasonId)
    {
        try {

            $season = Season::where('id', $seasonId)->first();

            if (!$season) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Season not found'
                ], 404);
            }

            $episodes = Episode::where('season_id', $seasonId)
                ->orderBy('episode_number')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $episodes
            ]);

        } catch (\Exception $e) {

            Log::error('Episode index error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch episodes: ' . $e->getMessage()
            ], 500);
        }
    }

    // Create episode
    public function store(Request $request)
    {
        try {

            $validated = $request->validate([
                'season_id' => 'required|string|exists:seasons,id',
                'episode_number' => 'required|integer',
                'title' => 'nullable|string',
                'description' => 'nullable|string',
                'thumbnail' => 'nullable|string',
                'release_date' => 'nullable|date',
                'is_active' => 'nullable|boolean'
            ]);

            $episode = Episode::create([
                'id' => (string) Str::uuid(),
                'season_id' => $validated['season_id'],
                'episode_number' => $validated['episode_number'],
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'] ?? null,
                'thumbnail' => $validated['thumbnail'] ?? null,
                'release_date' => $validated['release_date'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $episode
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            Log::error('Episode create error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create episode: ' . $e->getMessage()
            ], 500);
        }
    }

    // Show single episode
    public function show($id)
    {
        try {

            $episode = Episode::with('season')->where('id', $id)->first();

            if (!$episode) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Episode not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $episode
            ]);

        } catch (\Exception $e) {

            Log::error('Episode show error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch episode: ' . $e->getMessage()
            ], 500);
        }
    }

    // Update episode
    public function update(Request $request, $id)
    {
        try {

            $episode = Episode::where('id', $id)->first();

            if (!$episode) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Episode not found'
                ], 404);
            }

            $validated = $request->validate([
                'season_id' => 'nullable|string|exists:seasons,id',
                'episode_number' => 'nullable|integer',
                'title' => 'nullable|string',
                'description' => 'nullable|string',
                'thumbnail' => 'nullable|string',
                'release_date' => 'nullable|date',
                'is_active' => 'nullable|boolean'
            ]);

            $episode->update($validated);

            return response()->json([
                'status' => 'success',
                'data' => $episode->fresh()
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            Log::error('Episode update error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update episode: ' . $e->getMessage()
            ], 500);
        }
    }

    // Delete episode
    public function destroy($id)
    {
        try {

            $episode = Episode::where('id', $id)->first();

            if (!$episode) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Episode not found'
                ], 404);
            }

            $episode->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Episode deleted'
            ]);

        } catch (\Exception $e) {

            Log::error('Episode delete error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete episode: ' . $e->getMessage()
            ], 500);
        }
    }
}