<?php

namespace App\Http\Controllers;

use App\Models\MovieModel;
use Illuminate\Http\Request;

class AlsoLikeController extends Controller
{
    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key');
    }

    public function alsoLike(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
        }

        $movieTitle = $request->query('movie_title');
        $ageRestriction = $request->boolean('age_restriction', false);

        // ✅ Detect platform (header first, fallback to query)
        $platform = strtolower($request->header('X-Platform') ?? $request->query('platform', ''));

        // ✅ Platforms that should only return Mizo movies
        $mizoPlatforms = ['android', 'ios', 'tvos', 'tv', 'smarttv', 'firetv', 'roku', 'webos'];

        if (!$movieTitle) {
            return response()->json(['error' => 'Missing movie_title parameter'], 400);
        }

        // ✅ Fetch movies based on platform + restriction
        $isMizoOnly = in_array($platform, $mizoPlatforms, true);
        $movies = $this->fetchMovies($ageRestriction, $isMizoOnly);

        // ✅ Generate recommendations
        $recommended = $this->getRecommendations($movieTitle, $movies);

        return response()->json($recommended);
    }

    private function fetchMovies(bool $ageRestriction, bool $isMizoOnly = false)
    {
        $query = MovieModel::where('isEnable', 1);

        if (!$ageRestriction) {
            $query->where('isAgeRestricted', 0);
        }

        // ✅ Return only Mizo movies if platform is in the list
        if ($isMizoOnly) {
            $query->where('isMizo', 1);
        }

        $movies = $query->get()->toArray();

        // Convert specific fields to boolean
        $booleanFields = [
            'isEnable', 'isAgeRestricted', 'isBollywood', 'isHollywood',
            'isKorean', 'isMizo', 'isDocumentary', 'isSeason'
        ];

        foreach ($movies as &$movie) {
            foreach ($booleanFields as $field) {
                if (isset($movie[$field])) {
                    $movie[$field] = (bool) $movie[$field];
                }
            }
        }

        return $movies;
    }

    private function findBestMatch($inputTitle, $movies)
    {
        $bestMatch = null;
        $bestScore = 0;

        foreach ($movies as $movie) {
            similar_text(strtolower($inputTitle), strtolower(trim($movie['title'])), $score);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $movie;
            }
        }

        return ($bestScore >= 60) ? $bestMatch : null;
    }

    private function filterSequels($match, $movies)
    {
        $sequels = [];

        foreach ($movies as $movie) {
            if ($movie['title'] !== $match['title']) {
                similar_text($match['title'], $movie['title'], $score);
                if ($score > 80) {
                    $sequels[$movie['id']] = $movie;
                }
            }
        }

        return array_values($sequels);
    }

    private function filterGenreAndCategory($match, $movies)
    {
        $filtered = [];
        $genre = $match['genre'];
        $categories = ['isBollywood', 'isHollywood', 'isKorean', 'isMizo', 'isDocumentary', 'isSeason'];

        foreach ($movies as $movie) {
            if ($movie['title'] !== $match['title'] && strpos($movie['genre'], $genre) !== false) {
                foreach ($categories as $cat) {
                    if (!empty($match[$cat]) && !empty($movie[$cat])) {
                        $filtered[$movie['id']] = $movie;
                        break;
                    }
                }
            }
        }

        return array_values($filtered);
    }

    private function getRecommendations($title, $movies)
    {
        $match = $this->findBestMatch($title, $movies);

        if (!$match) {
            shuffle($movies);
            return array_slice($movies, 0, 20);
        }

        $sequels = $this->filterSequels($match, $movies);
        $related = $this->filterGenreAndCategory($match, $movies);

        shuffle($related);

        $recommendations = array_merge($sequels, $related);
        $unique = [];

        foreach ($recommendations as $movie) {
            $unique[$movie['id']] = $movie;
        }

        if (count($unique) < 20) {
            shuffle($movies);
            foreach ($movies as $movie) {
                if ($movie['title'] !== $match['title'] && !isset($unique[$movie['id']])) {
                    $unique[$movie['id']] = $movie;
                }
                if (count($unique) >= 20) break;
            }
        }

        return array_slice(array_values($unique), 0, 20);
    }
}
