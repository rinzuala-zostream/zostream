<?php

namespace App\Http\Controllers;

use App\Models\MovieModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MovieSearchController extends Controller
{
    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key'); // Set in .env or config/app.php
    }

    public function search(Request $request)
    {
        // API key validation
        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
        }

        $query = trim($request->query('q', ''));
        $ageRestriction = $request->query('age_restriction') === 'true' ? 1 : 0;

        if (empty($query)) {
            return response()->json(['error' => 'Search query is required.'], 400);
        }

        // Base query
        $moviesQuery = MovieModel::where('isEnable', 1)
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', '%' . $query . '%')
                    ->orWhere('genre', 'like', '%' . $query . '%');
            });

        if ($ageRestriction === 0) {
            $moviesQuery->where('isAgeRestricted', 0);
        }

        $movies = $moviesQuery->limit(20)->get()->toArray();

        // Prioritize sequels
        $movies = $this->prioritizeSequels($movies, $query);

        return response()->json($movies);
    }

    private function prioritizeSequels(array $movies, string $query): array
    {
        $query = strtolower($query);

        usort($movies, function ($a, $b) use ($query) {
            $aTitle = strtolower($a['title']);
            $bTitle = strtolower($b['title']);

            $aScore = 0;
            $bScore = 0;

            // Exact match
            if ($aTitle === $query)
                $aScore += 10;
            if ($bTitle === $query)
                $bScore += 10;

            // Title starts with query
            if (str_starts_with($aTitle, $query))
                $aScore += 5;
            if (str_starts_with($bTitle, $query))
                $bScore += 5;

            // Contains query
            if (strpos($aTitle, $query) !== false)
                $aScore += 2;
            if (strpos($bTitle, $query) !== false)
                $bScore += 2;

            // Contains number (likely a sequel)
            if (preg_match('/\d+/', $aTitle))
                $aScore += 1;
            if (preg_match('/\d+/', $bTitle))
                $bScore += 1;

            return $bScore <=> $aScore; // Higher score first
        });

        return $movies;
    }
}
