<?php

namespace App\Http\Controllers;

use App\Models\UserModel;
use App\Models\WatchHistoryModel;
use Carbon\Carbon;
use Illuminate\Http\Request;

class WatchPositionController extends Controller
{
    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key');
    }

    public function save(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key']);
        }

        $request->validate([
            'movie_id' => 'required|string',
            'position' => 'required|integer',
            'duration' => 'nullable|integer',
            'user_id' => 'required|string',
            'cover_img' => 'required|string',
            'movie_type' => 'nullable|string',
            'title' => 'nullable|string', // <-- new validation
        ]);

        try {
            $movieId = $request->input('movie_id');
            $position = $request->input('position');
            $duration = $request->input('duration');
            $userId = $request->input('user_id');
            $coverImg = $request->input('cover_img');
            $movieType = $request->input('movie_type');
            $title = $request->input('title');

            WatchHistoryModel::updateOrInsert(
                ['movie_id' => $movieId, 'user_id' => $userId],
                [
                    'position' => $position,
                    'movie_type' => $movieType,
                    'cover_img' => $coverImg,
                    'title' => $title,
                    'duration' => $duration,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            return response()->json(['status' => 'success', 'message' => 'Record saved successfully']);

        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function getWatchContinue(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key']);
        }

        $request->validate([
            'userId' => 'required|string',
            'isAgeRestricted' => 'required|string|in:true,false',
        ]);

        $userId = $request->query('userId');
        $isAgeRestricted = ($request->query('isAgeRestricted') ?? 'false') === 'true' ? 1 : 0;

        if (!$userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing userId or movieId'
            ], 400);
        }

        // Get watch position
        $watchData = WatchHistoryModel::where('user_id', $userId)
            ->first();

        $result = [];

        foreach ($watchData as $history) {
            $movie = $movies[$history->movie_id] ?? null;

            // Skip if movie not found or is age restricted
            if (!$movie || ($movie->is_age_restricted && $isAgeRestricted)) {
                continue;
            }

            $result[] = [
                'movie_id' => $history->movie_id,
                'position' => $history->position,
                'updated_at' => $history->updated_at,
                'movie' => $movie
            ];
        }
    }

    public function getWatchPosition(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key']);
        }

        $request->validate([
            'userId' => 'required|string',
            'movieId' => 'required|string',
            'isAgeRestricted' => 'required|string|in:true,false',
        ]);

        $userId = $request->query('userId');
        $movieId = $request->query('movieId');
        $isAgeRestricted = ($request->query('isAgeRestricted') ?? 'false') === 'true' ? 1 : 0;

        if (!$userId || !$movieId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing userId or movieId'
            ], 400);
        }

        // Get user age
        $userAge = null;
        $user = UserModel::select('dob')->where('uid', $userId)->first();
        if ($user && $user->dob) {
            $userAge = Carbon::parse($user->dob)->age;
        }

        // Age restriction check
        if ($isAgeRestricted && ($userAge === null || $userAge < 18)) {
            return response()->json([
                'status' => '104',
                'message' => 'Age restriction avangin i en thei lo. Khawngaihin adang en rawh',
                'age' => $userAge
            ]);
        }

        // Get watch position
        $watchData = WatchHistoryModel::select('position')
            ->where('user_id', $userId)
            ->where('movie_id', $movieId)
            ->first();

        $watchPosition = $watchData->position ?? 0;

        return response()->json([
            'status' => 'success',
            'watchPosition' => $watchPosition,
            'age' => $userAge
        ]);
    }

    public function getWatchHistory(Request $request)
{
    $apiKey = $request->header('X-Api-Key');

    if ($apiKey !== $this->validApiKey) {
        return response()->json(['status' => 'error', 'message' => 'Invalid API key']);
    }

    $request->validate([
        'userId' => 'required|string',
    ]);

    $userId = $request->query('userId');

    $history = WatchHistoryModel::where('user_id', $userId)
        ->orderByDesc('updated_at')
        ->get(['movie_id', 'position', 'movie_type', 'updated_at', 'cover_img', 'title', 'duration']);

    return response()->json([
        'status' => 'success',
        'history' => $history,
    ]);
}
}
