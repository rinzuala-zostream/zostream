<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Models\MovieModel;
use App\Support\Catalog\MovieRecommendationIndex;
use App\Support\MizoOnlyContent;
use Illuminate\Http\Request;

class AlsoLikeController extends Controller
{
    public function alsoLike(Request $request)
    {

        $movieTitle = $request->query('movie_title');
        $ageRestriction = $request->boolean('age_restriction', false);
        $modeHeader = strtolower((string) $request->header('X-Mode', ''));
        $isKidsByHeader = $modeHeader === 'kids';
        $isKidsByQuery = ($request->query('isChildMode') ?? 'false') === 'true';
        $isKidsMode = $isKidsByHeader || $isKidsByQuery;

        if (! $movieTitle) {
            return response()->json(['error' => 'Missing movie_title parameter'], 400);
        }

        // ✅ Read user ID
        $userId = MizoOnlyContent::userId($request);

        // ✅ Mizo-only logic (same as home/search for guest + special user)
        // $onlyMizoUser = $userId === 'AW7ovVnTdgWuvE1Uke7QTQ5OEQt1';
        $onlyMizoUser = empty($userId)
            || $userId === 'guest'
            || MizoOnlyContent::appliesTo($userId);

        $ranking = app(MovieRecommendationIndex::class)->ranking(
            (string) $movieTitle,
            $ageRestriction,
            $onlyMizoUser,
            $isKidsMode,
        );
        $recommendedNums = $this->selectRecommendations($ranking);

        // The legacy endpoint returns every movie column except playback
        // secrets. Fetch full models only for the final (at most 20) results so
        // casts and its JSON contract remain identical.
        $models = MovieModel::query()->whereIn('num', $recommendedNums)->get()->keyBy('num');
        $recommended = [];
        foreach ($recommendedNums as $num) {
            $model = $models->get($num);
            if ($model) {
                $recommended[] = $model->toArray();
            }
        }

        return response()->json(array_values(array_map(
            fn ($movie) => $this->transformMovie($movie),
            $recommended
        )));
    }

    private function selectRecommendations(array $ranking): array
    {
        $movies = $ranking['candidates'];

        if (! $ranking['matched']) {
            shuffle($movies);

            return array_column(array_slice($movies, 0, 20), 'num');
        }

        $movies = $ranking['candidates'];
        $sequels = array_map(static fn (int $index): array => $movies[$index], array_values($ranking['sequels']));
        $related = array_map(static fn (int $index): array => $movies[$index], array_values($ranking['related']));

        shuffle($related);

        $recommendations = array_merge($sequels, $related);
        $unique = [];
        $matchTitle = $ranking['match_title'];

        foreach ($recommendations as $movie) {
            $unique[$movie['id']] = $movie;
        }

        if (count($unique) < 20) {
            shuffle($movies);
            foreach ($movies as $movie) {
                if ($movie['title'] !== $matchTitle
                    && ! isset($unique[$movie['id']])) {
                    $unique[$movie['id']] = $movie;
                }
                if (count($unique) >= 20) {
                    break;
                }
            }
        }

        return array_column(array_slice(array_values($unique), 0, 20), 'num');
    }

    private function transformMovie(array $movie): array
    {
        foreach ([
            'isProtected',
            'isBollywood',
            'isCompleted',
            'isDocumentary',
            'isDubbed',
            'isEnable',
            'isHollywood',
            'isKorean',
            'isMizo',
            'isPayPerView',
            'isPremium',
            'isAgeRestricted',
            'isSeason',
            'isSubtitle',
            'isChildMode',
        ] as $key) {
            if (array_key_exists($key, $movie)) {
                $movie[$key] = (bool) $movie[$key];
            }
        }

        if (array_key_exists('num', $movie)) {
            $movie['num'] = (int) $movie['num'];
        }

        if (array_key_exists('views', $movie)) {
            $movie['views'] = (int) $movie['views'];
        }

        unset(
            $movie['url'],
            $movie['dash_url'],
            $movie['hls_url'],
            $movie['token']
        );

        return $movie;
    }
}
