<?php

namespace App\Http\Controllers;

use App\Models\MovieModel;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function searchMovies(Request $request)
    {
        $rawQuery = $request->input('q');
        $perPage = $request->input('per_page', 10);

        if (!$rawQuery) {
            return response()->json(['message' => 'Search query is required.'], 400);
        }

        // ✅ Kids mode + platform detection
        $modeHeader = strtolower($request->header('X-Mode', ''));
        $isKidsByHeader = $modeHeader === 'kids';
        $isKidsByQuery = ($request->query('isChildMode') ?? 'false') === 'true';
        $isKidsMode = $isKidsByHeader || $isKidsByQuery;

        $platform = strtolower($request->header('X-Platform') ?? $request->query('platform', ''));

        $hiddenByPlatform = [
            'ios' => ['Hollywood', 'Bollywood', '18+', 'Asian', 'Series', 'Documentary', 'Animation'],
            'tvos' => ['18+'],
            'macos' => [],
            'android' => [],
            'web' => [],
            '_default' => ['18+'],
        ];

        $hiddenCategories = $hiddenByPlatform[$platform] ?? $hiddenByPlatform['_default'];

        // ✅ Skip check definitions
        $skipChecks = [
            'Hollywood' => fn($m) => (int) ($m->isHollywood ?? 0) === 1,
            'Bollywood' => fn($m) => (int) ($m->isBollywood ?? 0) === 1,
            'Mizo' => fn($m) => (int) ($m->isMizo ?? 0) === 1,
            'Asian' => fn($m) => (int) ($m->isKorean ?? 0) === 1,
            'Series' => fn($m) => (int) ($m->isSeason ?? 0) === 1,
            'Documentary' => fn($m) => (int) ($m->isDocumentary ?? 0) === 1,
            '18+' => fn($m) => (int) ($m->isAgeRestricted ?? 0) === 1,
            'Animation' => fn($m) => stripos((string) ($m->genre ?? ''), 'animation') !== false,
        ];

        $shouldSkip = function ($movie) use ($isKidsMode, $hiddenCategories, $skipChecks) {
            if ($isKidsMode && (int) ($movie->isChildMode ?? 0) !== 1) {
                return true;
            }
            foreach ($hiddenCategories as $name) {
                if (isset($skipChecks[$name]) && $skipChecks[$name]($movie)) {
                    return true;
                }
            }
            return false;
        };

        // ✅ Clean up the query for boolean search
        $cleanQuery = $this->cleanFullTextInput($rawQuery) . '*';

        // Step 1: Fulltext Search
        $exactMatches = MovieModel::selectRaw("*, MATCH(title, genre) AGAINST (? IN BOOLEAN MODE) AS relevance", [$cleanQuery])
            ->whereRaw("MATCH(title, genre) AGAINST (? IN BOOLEAN MODE)", [$cleanQuery])
            ->orderByDesc('relevance')
            ->paginate($perPage);

        $filtered = $exactMatches->getCollection()->reject($shouldSkip)->values();
        if ($filtered->count() > 0) {
            $exactMatches->setCollection($filtered);
            return response()->json([
                'type' => 'exact',
                'query' => $rawQuery,
                'results' => $exactMatches
            ]);
        }

        // Step 2: LIKE Fallback
        $fallbackMatches = MovieModel::where(function ($q) use ($rawQuery) {
            $q->where('title', 'LIKE', "%{$rawQuery}%")
              ->orWhere('genre', 'LIKE', "%{$rawQuery}%")
              ->orWhereRaw("SOUNDEX(title) = SOUNDEX(?)", [$rawQuery]);
        })->paginate($perPage);

        $filteredFallback = $fallbackMatches->getCollection()->reject($shouldSkip)->values();
        if ($filteredFallback->count() > 0) {
            $fallbackMatches->setCollection($filteredFallback);
            return response()->json([
                'type' => 'fallback_like',
                'query' => $rawQuery,
                'results' => $fallbackMatches
            ]);
        }

        // Step 3: Related
        $related = MovieModel::inRandomOrder()->limit(10)->get()
            ->reject($shouldSkip)
            ->values();

        return response()->json([
            'type' => 'related',
            'query' => $rawQuery,
            'message' => 'No direct match found, showing related movies.',
            'results' => $related
        ]);
    }

    private function cleanFullTextInput($query)
    {
        return preg_replace('/[+\-><\(\)~*\"@]+/', ' ', $query);
    }
}
