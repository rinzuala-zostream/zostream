<?php

namespace App\Http\Controllers;

use App\Models\WatchHistoryModel;
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
}
