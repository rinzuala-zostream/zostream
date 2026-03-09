<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Models\New\Season;
use Illuminate\Http\Request;
use Str;

class SeasonController extends Controller
{

    // Get all seasons of a movie
    public function index($movieId)
    {
        $seasons = Season::where('movie_id', $movieId)
            ->orderBy('season_number')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $seasons
        ]);
    }

    // Create season
    public function store(Request $request)
    {
        $request->validate([
            'movie_id' => 'required|integer',
            'season_number' => 'required|integer',
        ]);

        $season = Season::create([
            'id' => Str::random(20),
            'movie_id' => $request->movie_id,
            'season_number' => $request->season_number,
            'title' => $request->title,
            'description' => $request->description,
            'poster' => $request->poster,
            'release_year' => $request->release_year,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $season
        ]);
    }

    // Show season
    public function show($id)
    {
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
    }

    // Update season
    public function update(Request $request, $id)
    {
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
    }

    // Delete season
    public function destroy($id)
    {
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
    }
}
