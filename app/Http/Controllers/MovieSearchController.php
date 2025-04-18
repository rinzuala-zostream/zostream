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

        // Convert fields
        $movies = array_map(function ($movie) {
            $boolFields = [
                "isProtected", "isBollywood", "isCompleted", "isDocumentary",
                "isDubbed", "isEnable", "isHollywood", "isKorean", "isMizo",
                "isPayPerView", "isPremium", "isAgeRestricted", "isSeason", "isSubtitle"
            ];
        
            foreach ($boolFields as $field) {
                $movie[$field] = (bool) $movie[$field];
            }
        
            $movie['num'] = (int) $movie['num'];
            $movie['views'] = (int) $movie['views'];
        
            return $movie;
        }, $movies);        

        return response()->json($movies);
    }

    private function prioritizeSequels(array $movies, string $query): array
    {
        $sequels = [];
        $others = [];
    
        foreach ($movies as $movie) {
            if (preg_match('/' . preg_quote($query, '/') . ' (\d+)$/i', $movie['title'], $matches)) {
                $sequels[(int)$matches[1]] = $movie;
            } else {
                $others[] = $movie;
            }
        }
    
        ksort($sequels);
        return array_merge(array_values($sequels), $others);
    }
    
}
