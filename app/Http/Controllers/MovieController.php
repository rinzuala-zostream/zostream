<?php

namespace App\Http\Controllers;

use App\Models\EpisodeModel;
use App\Models\MovieModel;
use Illuminate\Http\Request;

class MovieController extends Controller
{
    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key');
    }

    public function getMovies(Request $request)
    {

        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key']);
        }

        $request->validate([
            'id' => 'nullable|string',
            'range' => 'nullable|string',
            'category' => 'nullable|string',
            'category_type' => 'nullable|string',
            'age_restriction' => 'nullable|string|in:true,false',
        ]);

        $id = $request->query('id') ?? null;
        $range = $request->query('range') ?? null;
        $categoryKey = strtolower($request->query('category') ?? '');
        $categoryType = strtolower($request->query('category_type') ?? '');
        $ageRestriction = ($request->query('age_restriction') ?? 'false') === 'true' ? 1 : 0;

        if ($id) {
            $movie = MovieModel::where('id', $id)->first();

            if (!$movie) {
                return response()->json(['status' => 'error', 'message' => 'Movie not found']);
            }

            return response()->json(
                $this->transformMovie($movie)
            )->header('Content-Type', 'application/json');
        } else if ($range || $categoryKey) {

            $rangeParts = explode('-', $range ?? '1-10');
            $from = max((int) $rangeParts[0], 1);
            $to = max((int) $rangeParts[1], $from + 9);
            $start = $from - 1;
            $count = $to - $from + 1;

            $categoryMapping = [
                "hollywood" => "isHollywood",
                "bollywood" => "isBollywood",
                "mizo" => "isMizo",
                "animation" => "genre",
                "asian" => "isKorean",
                "most watched" => "mostwatch",
                "pay per view" => "isPayPerView",
                "new release" => "newrelease",
                "latest update" => "all",
                "series" => "isSeason",
                "documentary" => "isDocumentary",
                "18+" => "isAgeRestricted",
                "free" => "free",
            ];

            $column = $categoryType ? $categoryKey : ($categoryMapping[$categoryKey] ?? null);

            if (!$column) {
                return response()->json(['status' => 'error', 'message' => 'Invalid category']);
            }

            $query = MovieModel::query()->where('isEnable', 1);

            if ($column === 'newrelease') {
                $query->whereNotNull('release_on')
                    ->when(!$ageRestriction, fn($q) => $q->where('isAgeRestricted', $ageRestriction))
                    ->orderByRaw("STR_TO_DATE(release_on, '%d %b, %Y') DESC");
            } elseif ($column === 'mostwatch') {
                $query->when(!$ageRestriction, fn($q) => $q->where('isAgeRestricted', $ageRestriction))
                    ->orderByDesc('views');
            } elseif ($column === 'all') {
                $query->when(!$ageRestriction, fn($q) => $q->where('isAgeRestricted', $ageRestriction))
                    ->orderByDesc('num');
            } elseif ($column === 'free') {
                $query->where('isPremium', 0)
                    ->when(!$ageRestriction, fn($q) => $q->where('isAgeRestricted', $ageRestriction))
                    ->orderByDesc('num');
            } elseif (
                in_array($column, [
                    "isBollywood",
                    "isCompleted",
                    "isDocumentary",
                    "isDubbed",
                    "isEnable",
                    "isHollywood",
                    "isKorean",
                    "isMizo",
                    "isPayPerView",
                    "isPremium",
                    "isAgeRestricted",
                    "isSeason",
                    "isSubtitle"
                ])
            ) {
                $query->where($column, 1)
                    ->when(!$ageRestriction, fn($q) => $q->where('isAgeRestricted', $ageRestriction))
                    ->orderByDesc('num');
            } else {
                $query->where('genre', 'LIKE', "%$categoryKey%")
                    ->when(!$ageRestriction, fn($q) => $q->where('isAgeRestricted', $ageRestriction))
                    ->orderByDesc('num');
            }

            $movies = $query->offset($start)->limit($count)->get();

            return response()->json(
                data: $movies->map(fn($m) => $this->transformMovie($m))
            )->header('Content-Type', 'application/json');
        } else {
            $categories = [
                "New Release" => ["where" => "release_on IS NOT NULL", "order" => "STR_TO_DATE(release_on, '%d %b, %Y') DESC"],
                "Most Watched" => ["where" => "1", "order" => "views DESC"],
                "Pay Per View" => ["where" => "isPayPerView = 1", "order" => "num DESC"],
                "Latest Update" => ["where" => "1", "order" => "num DESC"],
                "Asian" => ["where" => "isKorean = 1", "order" => "num DESC"],
                "Series" => ["where" => "isSeason = 1", "order" => "num DESC"],
                "Hollywood" => ["where" => "isHollywood = 1", "order" => "num DESC"],
                "Animation" => ["where" => "genre LIKE '%Animation%'", "order" => "num DESC"],
                "18+" => ["where" => "isAgeRestricted = 1", "order" => "num DESC"],
                "Bollywood" => ["where" => "isBollywood = 1", "order" => "num DESC"],
                "Mizo" => ["where" => "isMizo = 1", "order" => "num DESC"],
                "Documentary" => ["where" => "isDocumentary = 1", "order" => "num DESC"],
                "Free" => ["where" => "isPremium = 0", "order" => "num DESC"],
            ];

            $data = [];

            foreach ($categories as $name => $clause) {
                if ($name === "18+" && !$ageRestriction)
                    continue;

                $where = $clause['where'];
                $order = $clause['order'];

                $query = MovieModel::whereRaw("isEnable = 1 AND $where")
                    ->when(!$ageRestriction && strpos($where, 'isAgeRestricted') === false, function ($q) use ($ageRestriction) {
                        return $q->where('isAgeRestricted', $ageRestriction);
                    })
                    ->orderByRaw($order)
                    ->limit(10)
                    ->get();

                if (!$query->isEmpty()) {
                    $data[$name] = $query->map(fn($m) => $this->transformMovie($m));
                }
            }

            // Return the JSON response with actual data, and set proper response headers
            return response()->json(
                $data
            )->header('Content-Type', 'application/json'); // Ensure proper content type
        }
    }

    private function transformMovie($movie)
    {
        foreach (['isProtected', 'isBollywood', 'isCompleted', 'isDocumentary', 'isDubbed', 'isEnable', 'isHollywood', 'isKorean', 'isMizo', 'isPayPerView', 'isPremium', 'isAgeRestricted', 'isSeason', 'isSubtitle'] as $key) {
            $movie->$key = (bool) $movie->$key;
        }

        $movie->num = (int) $movie->num;
        $movie->views = (int) $movie->views;

        return $movie;
    }

    public function incrementView(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key']);
        }

        $request->validate([
            'movie_id' => 'required|string',
            'movie_type' => 'required|string|in:movie,episode',
        ]);

        $id = $request->query('movie_id');
        $type = strtolower($request->query('movie_type'));

        if ($type === 'movie') {
            $movie = MovieModel::where('id', $id)->first();
        } elseif ($type === 'episode') {
            // Assuming you have an EpisodeModel for episodes
            $movie = EpisodeModel::where('id', $id)->first();
        } else {
            return response()->json(['status' => 'error', 'message' => 'Invalid type']);
        }

        if (!$movie) {
            return response()->json(['status' => 'error', 'message' => 'Content not found']);
        }

        $movie->increment('views');

        return response()->json(['status' => 'success', 'message' => 'View count incremented']);
    }


}
