<?php

namespace App\Http\Controllers;

use App\Models\EpisodeModel;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Str;

class EpisodeController extends Controller
{
    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key');
    }
    public function getBySeason(Request $request)
    {
        // Get API key from request headers
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key'], 401);
        }

        $seasonId = $request->query('id');

        if (!$seasonId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing season ID'
            ], 400);
        }

        $episodes = EpisodeModel::where('season_id', $seasonId)
            ->where('isEnable', 1)
            ->orderByRaw("CAST(SUBSTRING_INDEX(title, 'Episode ', -1) AS UNSIGNED)")
            ->get();

        if ($episodes->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Episodes not found'
            ], 404);
        }

        return response()->json($episodes);
    }

    public function insert(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key']);
        }

        // Validate incoming request data
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'desc' => 'nullable|string',
            'txt' => 'nullable|string',
            'season_id' => 'required|string',
            'img' => 'nullable|string',
            'url' => 'nullable|string',
            'dash_url' => 'nullable|string',
            'hls_url' => 'nullable|string',
            'token' => 'nullable|string',
            'ppv_amount' => 'nullable|string',
            'status' => 'nullable|string|in:Published,Scheduled,Draft',
            'create_date' => 'nullable|string',

            // Boolean flags
            'isProtected' => 'boolean',
            'isPPV' => 'boolean',
            'isPremium' => 'boolean',
            'isEnable' => 'boolean',
        ]);

        $validated['create_date'] = !empty($validated['create_date'])
            ? (new DateTime($validated['create_date']))->format('F j, Y')
            : now()->format('F j, Y');

        // Generate a unique ID
        $validated['id'] = Str::random(10); // or Str::random(10)

        // Set default views to 0
        $validated['views'] = 0;

        // Create the episode
        $episode = EpisodeModel::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Episode inserted successfully',
            'episode' => $episode
        ]);
    }

    public function update(Request $request, $id)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key'], 401);
        }

        $episode = EpisodeModel::where('id', $id)->first();

        if (!$episode) {
            return response()->json(['status' => 'error', 'message' => 'Episode not found'], 404);
        }

        // Validate the incoming request
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'desc' => 'nullable|string',
            'txt' => 'nullable|string',
            'season_id' => 'sometimes|required|string',
            'img' => 'nullable|string',
            'url' => 'nullable|string',
            'dash_url' => 'nullable|string',
            'hls_url' => 'nullable|string',
            'token' => 'nullable|string',
            'ppv_amount' => 'nullable|string',
            'isProtected' => 'boolean',
            'isPPV' => 'boolean',
            'isPremium' => 'boolean',
            'isEnable' => 'boolean',
            'status' => 'nullable|string|in:Published,Scheduled,Draft',
            'create_date' => 'nullable|string',
        ]);

        $validated['create_date'] = !empty($validated['create_date'])
            ? (new DateTime($validated['create_date']))->format('F j, Y')
            : now()->format('F j, Y');

        $episode->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Episode updated successfully',
            'episode' => $episode
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key'], 401);
        }

        $episode = EpisodeModel::where('id', $id)->first();

        if (!$episode) {
            return response()->json(['status' => 'error', 'message' => 'Episode not found'], 404);
        }

        $episode->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Episode deleted successfully'
        ]);
    }
}
