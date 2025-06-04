<?php

namespace App\Http\Controllers;

use App\Models\MovieModel;
use Illuminate\Http\Request;

class MovieSearchController extends Controller
{
    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key'); // Set in .env or config/app.php
    }

    public function search(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
        }

        $query = strtolower(trim(preg_replace('/\s+/', ' ', $request->query('q', ''))));
        if (empty($query)) {
            return response()->json(['error' => 'Search query is required.'], 400);
        }

        // Parse booleans with defaults
        $isEnableRequest = filter_var($request->query('is_enable', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $ageRestriction = filter_var($request->query('age_restriction', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $isEnableRequest = $isEnableRequest === null ? true : $isEnableRequest;
        $ageRestriction = $ageRestriction === null ? false : $ageRestriction;

        // Build base query
        $moviesQuery = MovieModel::query();

        if ($isEnableRequest) {
            $moviesQuery->where('status', 'Published')->where('isEnable', 1);
        }

        $moviesQuery->where(function ($q) use ($query) {
            $q->whereRaw('LOWER(title) LIKE ?', ['%' . $query . '%'])
                ->orWhereRaw('LOWER(genre) LIKE ?', ['%' . $query . '%']);
        });

        if (!$ageRestriction) {
            $moviesQuery->where('isAgeRestricted', 0);
        }

        $movies = $moviesQuery->limit(50)->get()->toArray();

        if (empty($movies)) {
            return response()->json(['message' => 'No movies found for the given query.'], 404);
        }

        $sortedMovies = $this->prioritizeSequels($movies, $query);

        return response()->json(array_slice($sortedMovies, 0, 20));
    }

    private function prioritizeSequels(array $movies, string $query): array
    {
        $query = strtolower($query);

        usort($movies, function ($a, $b) use ($query) {
            $aTitle = strtolower($a['title']);
            $bTitle = strtolower($b['title']);

            $aScore = 0;
            $bScore = 0;

            if ($aTitle === $query)
                $aScore += 10;
            if ($bTitle === $query)
                $bScore += 10;

            if (str_starts_with($aTitle, $query))
                $aScore += 5;
            if (str_starts_with($bTitle, $query))
                $bScore += 5;

            if (strpos($aTitle, $query) !== false)
                $aScore += 2;
            if (strpos($bTitle, $query) !== false)
                $bScore += 2;

            if (preg_match('/\d+/', $aTitle))
                $aScore += 1;
            if (preg_match('/\d+/', $bTitle))
                $bScore += 1;

            return $bScore <=> $aScore;
        });

        return $movies;
    }
}
