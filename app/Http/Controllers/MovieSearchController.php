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

    public function searchMovies(Request $request)
    {
        // ✅ API Key check
        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
        }

        // ✅ Parse query
        $rawQuery = strtolower(trim(preg_replace('/\s+/', ' ', $request->query('q', ''))));
        if (empty($rawQuery)) {
            return response()->json(['error' => 'Search query is required.'], 400);
        }

        // ✅ Boolean query parameters
        $isEnableRequest = filter_var($request->query('is_enable', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $ageRestriction = filter_var($request->query('age_restriction', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $isEnableRequest = $isEnableRequest === null ? true : $isEnableRequest;
        $ageRestriction = $ageRestriction === null ? false : $ageRestriction;

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

        $hiddenCategories = [];
        if ($platform !== '') {
            $hiddenCategories = $hiddenByPlatform[$platform] ?? $hiddenByPlatform['_default'];
        } elseif ($isKidsMode) {
            $hiddenCategories = $hiddenByPlatform['_default'];
        }

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

        $cleanQuery = $this->cleanFullTextInput($rawQuery) . '*';
        $perPage = 20;

        // ✅ Step 1: Fulltext search
        $exactMatches = MovieModel::selectRaw("*, MATCH(title, genre) AGAINST (? IN BOOLEAN MODE) AS relevance", [$cleanQuery])
            ->whereRaw("MATCH(title, genre) AGAINST (? IN BOOLEAN MODE)", [$cleanQuery])
            ->when($isEnableRequest, fn($q) => $q->where('status', 'Published')->where('isEnable', 1))
            ->when(!$ageRestriction, fn($q) => $q->where('isAgeRestricted', 0))
            ->orderByDesc('relevance')
            ->paginate($perPage);

        $filtered = $exactMatches->getCollection()->reject($shouldSkip)->values();

        if ($filtered->count() > 0) {
            $finalResults = $this->prioritizeSequels($filtered->toArray(), $rawQuery);
            $exactMatches->setCollection(collect($finalResults));
            return response()->json($filtered->values());

        }

        // ✅ Step 2: LIKE fallback
        $fallbackMatches = MovieModel::where(function ($q) use ($rawQuery) {
                $q->where('title', 'LIKE', "%{$rawQuery}%")
                    ->orWhere('genre', 'LIKE', "%{$rawQuery}%")
                    ->orWhereRaw("SOUNDEX(title) = SOUNDEX(?)", [$rawQuery]);
            })
            ->when($isEnableRequest, fn($q) => $q->where('status', 'Published')->where('isEnable', 1))
            ->when(!$ageRestriction, fn($q) => $q->where('isAgeRestricted', 0))
            ->paginate($perPage);

        $filteredFallback = $fallbackMatches->getCollection()->reject($shouldSkip)->values();

        if ($filteredFallback->count() > 0) {
            $finalResults = $this->prioritizeSequels($filteredFallback->toArray(), $rawQuery);
            $fallbackMatches->setCollection(collect($finalResults));
            return response()->json($filteredFallback->values());

        }

        return response()->json(['message' => 'No movies found for the given query.'], 404);
    }

    private function cleanFullTextInput(string $text): string
    {
        // Remove special characters for fulltext safety
        return preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
    }

    // ✅ Prioritize sequels (keep this function)
    private function prioritizeSequels(array $movies, string $query): array
    {
        $query = strtolower($query);

        usort($movies, function ($a, $b) use ($query) {
            $aTitle = strtolower($a['title']);
            $bTitle = strtolower($b['title']);

            $aScore = 0;
            $bScore = 0;

            if ($aTitle === $query) $aScore += 10;
            if ($bTitle === $query) $bScore += 10;

            if (str_starts_with($aTitle, $query)) $aScore += 5;
            if (str_starts_with($bTitle, $query)) $bScore += 5;

            if (strpos($aTitle, $query) !== false) $aScore += 2;
            if (strpos($bTitle, $query) !== false) $bScore += 2;

            if (preg_match('/\d+/', $aTitle)) $aScore += 1;
            if (preg_match('/\d+/', $bTitle)) $bScore += 1;

            return $bScore <=> $aScore;
        });

        return $movies;
    }
}
