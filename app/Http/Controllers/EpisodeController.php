<?php

namespace App\Http\Controllers;

use App\Models\EpisodeModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

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
            ->get();

        if ($episodes->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Episodes not found'
            ], 404);
        }

        return response()->json($episodes);
    }
}
