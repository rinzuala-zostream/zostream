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

        // ✅ Kids mode and platform detection
        $modeHeader = strtolower($request->header('X-Mode', ''));
        $isKidsByHeader = $modeHeader === 'kids';
        $isKidsByQuery = ($request->query('isChildMode') ?? 'false') === 'true';
        $isKidsMode = $isKidsByHeader || $isKidsByQuery;

        // ✅ Platform detection (header first, then query)
        $platform = strtolower($request->header('X-Platform') ?? $request->query('platform', ''));

        // ✅ Configure which categories to hide per platform
        $hiddenByPlatform = [
            'ios' => ['Hollywood', 'Bollywood', '18+', 'Asian', 'Series', 'Documentary', 'Animation'],
            'tvos' => ['18+'],
            'macos' => [],
            'android' => [],
            'web' => [],
            '_default' => ['18+'],
        ];

        // ✅ Determine categories to hide
        $hiddenCategories = $hiddenByPlatform[$platform] ?? $hiddenByPlatform['_default'];

        // ✅ Clean query for BOOLEAN MODE
        $cleanQuery = $this->cleanFullTextInput($rawQuery) . '*';

        // ✅ Step 1: Fulltext Search
        $exactMatches = MovieModel::selectRaw("*, 
            MATCH(title, genre) AGAINST (? IN BOOLEAN MODE) AS relevance", [$cleanQuery])
            ->whereRaw("MATCH(title, genre) AGAINST (? IN BOOLEAN MODE)", [$cleanQuery])
            ->when($isKidsMode, fn($q) => $q->where('is_kids', true))
            ->when(!empty($hiddenCategories), fn($q) => $q->whereNotIn('category', $hiddenCategories))
            ->orderByDesc('relevance')
            ->paginate($perPage);

        if ($exactMatches->total() > 0) {
            return response()->json([
                'type' => 'exact',
                'query' => $rawQuery,
                'results' => $exactMatches
            ]);
        }

        // ✅ Step 2: LIKE fallback
        $fallbackMatches = MovieModel::where(function ($q) use ($rawQuery) {
                $q->where('title', 'LIKE', "%{$rawQuery}%")
                    ->orWhere('genre', 'LIKE', "%{$rawQuery}%")
                    ->orWhereRaw("SOUNDEX(title) = SOUNDEX(?)", [$rawQuery]);
            })
            ->when($isKidsMode, fn($q) => $q->where('is_kids', true))
            ->when(!empty($hiddenCategories), fn($q) => $q->whereNotIn('category', $hiddenCategories))
            ->paginate($perPage);

        if ($fallbackMatches->total() > 0) {
            return response()->json([
                'type' => 'fallback_like',
                'query' => $rawQuery,
                'results' => $fallbackMatches
            ]);
        }

        // ✅ Step 3: Related fallback (safe for kids/platform)
        $related = MovieModel::when($isKidsMode, fn($q) => $q->where('is_kids', true))
            ->when(!empty($hiddenCategories), fn($q) => $q->whereNotIn('category', $hiddenCategories))
            ->inRandomOrder()
            ->limit(10)
            ->get();

        return response()->json([
            'type' => 'related',
            'query' => $rawQuery,
            'message' => 'No direct match found, showing related movies.',
            'results' => $related
        ]);
    }

    // Clean query for BOOLEAN MODE to prevent syntax errors
    private function cleanFullTextInput($query)
    {
        return preg_replace('/[+\-><\(\)~*\"@]+/', ' ', $query);
    }
}
