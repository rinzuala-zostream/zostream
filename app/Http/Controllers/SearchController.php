<?php

namespace App\Http\Controllers;

use App\Models\MovieModel;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    // Clean input to avoid BOOLEAN MODE syntax errors
    public function searchMovies(Request $request)
    {
        $rawQuery = $request->input('q');
        $perPage = $request->input('per_page', 10);

        if (!$rawQuery) {
            return response()->json(['message' => 'Search query is required.'], 400);
        }

        $cleanQuery = $this->cleanFullTextInput($rawQuery) . '*';

        // Step 1: Fulltext Search
        $exactMatches = MovieModel::selectRaw("*, 
        MATCH(title, genre) 
        AGAINST (? IN BOOLEAN MODE) AS relevance", [$cleanQuery])
            ->whereRaw("MATCH(title, genre) 
        AGAINST (? IN BOOLEAN MODE)", [$cleanQuery])
            ->orderByDesc('relevance')
            ->paginate($perPage);

        if ($exactMatches->total() > 0) {
            return response()->json([
                'type' => 'exact',
                'query' => $rawQuery,
                'results' => $exactMatches
            ]);
        }

        // Step 2: LIKE Fallback (case-insensitive, supports typos like 'khudaa')
        $fallbackMatches = MovieModel::where(function ($q) use ($rawQuery) {
            $q->where('title', 'LIKE', "%{$rawQuery}%")
                ->orWhere('genre', 'LIKE', "%{$rawQuery}%")
                ->orWhereRaw("SOUNDEX(title) = SOUNDEX(?)", [$rawQuery]); // Soundex helps for similar sounding words
        })->paginate($perPage);

        if ($fallbackMatches->total() > 0) {
            return response()->json([
                'type' => 'fallback_like',
                'query' => $rawQuery,
                'results' => $fallbackMatches
            ]);
        }

        // Step 3: Related
        $related = MovieModel::inRandomOrder()->limit(10)->get();

        return response()->json([
            'type' => 'related',
            'query' => $rawQuery,
            'message' => 'No direct match found, showing related movies.',
            'results' => $related
        ]);
    }

    // Clean query for BOOLEAN MODE to prevent syntax errors (like "ant-" or "iron*man")
    private function cleanFullTextInput($query)
    {
        return preg_replace('/[+\-><\(\)~*\"@]+/', ' ', $query);
    }
}
