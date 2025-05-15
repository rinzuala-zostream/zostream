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

        // Validate input
        $request->validate([
            'movie_id'   => 'required|string',
            'position'   => 'required|integer',
            'user_id'    => 'required|string',
            'movie_type' => 'nullable|string',
        ]);

        try {

            $movieId   = $request->input('movie_id');
            $position  = $request->input('position');
            $userId    = $request->input('user_id');
            $movieType = $request->input('movie_type');

            // Check if the record exists
            $existing =  WatchHistoryModel::where('movie_id', $movieId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // Update existing
                WatchHistoryModel::where('movie_id', $movieId)
                    ->where('user_id', $userId)
                    ->update([
                        'position'    => $position,
                        'movie_type'  => $movieType,
                    ]);
            } else {
                // Insert new
                WatchHistoryModel::insert([
                    'movie_id'    => $movieId,
                    'position'    => $position,
                    'user_id'     => $userId,
                    'movie_type'  => $movieType,
                ]);
            }

            return response()->json(['status' => 'success', 'message' => 'Record saved successfully']);

        } catch (\Exception $e) {
           
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function getWatchPosition(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key']);
        }

        $request->validate([
            'userId' => 'nullable|string',
            'movieId' => 'nullable|string',
            'isAgeRestricted' => 'nullable|boolean',
        ]);

        $userId = $request->query('userId');
        $movieId = $request->query('movieId');
        $isAgeRestricted = $request->query('isAgeRestricted');

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
}
