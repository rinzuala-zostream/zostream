<?php

namespace Tests\Feature;

use App\Http\Controllers\New\AlsoLikeController;
use App\Http\Controllers\Api\V4\CatalogController;
use App\Models\MovieModel;
use App\Support\Catalog\MovieCatalogRevision;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AlsoLikeRecommendationTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = (string) config('database.default');
        config([
            'database.default' => 'also_like_testing',
            'database.connections.also_like_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('also_like_testing');
        DB::reconnect('also_like_testing');

        Schema::create('movie_catalog_revisions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(1);
        });
        DB::table('movie_catalog_revisions')->insert(['id' => 1, 'revision' => 1]);

        Schema::create('movie', function (Blueprint $table): void {
            $table->increments('num');
            $table->string('id')->unique();
            $table->string('title');
            $table->text('genre')->nullable();
            $table->string('status')->default('Published');
            $table->boolean('isEnable')->default(true);
            $table->boolean('isAgeRestricted')->default(false);
            $table->boolean('isChildMode')->default(false);
            $table->boolean('isMizo')->default(false);
            $table->boolean('isBollywood')->default(false);
            $table->boolean('isHollywood')->default(false);
            $table->boolean('isKorean')->default(false);
            $table->boolean('isDocumentary')->default(false);
            $table->boolean('isSeason')->default(false);
            $table->boolean('isProtected')->default(false);
            $table->boolean('isCompleted')->default(false);
            $table->boolean('isDubbed')->default(false);
            $table->boolean('isPayPerView')->default(false);
            $table->boolean('isPremium')->default(false);
            $table->boolean('isSubtitle')->default(false);
            $table->date('create_date')->nullable();
            $table->date('release_on')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->text('url')->nullable();
            $table->text('dash_url')->nullable();
            $table->text('hls_url')->nullable();
            $table->text('token')->nullable();
            $table->string('description')->nullable();
        });

        Cache::flush();
    }

    protected function tearDown(): void
    {
        DB::disconnect('also_like_testing');
        config(['database.default' => $this->originalConnection]);

        parent::tearDown();
    }

    public function test_ranking_and_audience_filters_keep_legacy_behavior_and_payload(): void
    {
        $this->insertMovie('source', 'The Star', 'Drama', [
            'isMizo' => 1, 'isChildMode' => 1, 'isBollywood' => 1, 'create_date' => '2026-01-02',
            'url' => 'private-url', 'dash_url' => 'private-dash', 'hls_url' => 'private-hls', 'token' => 'private-token',
        ]);
        $this->insertMovie('sequel', 'The Star 2', 'Drama', [
            'isMizo' => 1, 'isChildMode' => 1, 'isBollywood' => 1, 'create_date' => '2026-01-02',
        ]);
        $this->insertMovie('related', 'Quiet Film', 'Drama, Romance', [
            'isMizo' => 1, 'isChildMode' => 1, 'isBollywood' => 1,
        ]);
        $this->insertMovie('not-kids', 'The Star Kids', 'Drama', ['isMizo' => 1, 'isChildMode' => 0]);
        $this->insertMovie('not-mizo', 'The Star Elsewhere', 'Drama', ['isMizo' => 0, 'isChildMode' => 1]);

        $response = app(AlsoLikeController::class)->alsoLike(Request::create('/', 'GET', [
            'movie_title' => 'The Star',
            'isChildMode' => 'true',
        ]));
        $payload = $response->getData(true);
        $ids = array_column($payload, 'id');

        $this->assertSame('sequel', $ids[0]);
        $this->assertContains('related', $ids);
        $this->assertNotContains('not-kids', $ids);
        $this->assertNotContains('not-mizo', $ids);
        $this->assertSame(Carbon::parse('2026-01-02', config('app.timezone'))->toJSON(), $payload[0]['create_date']);
        $this->assertNotContains('url', array_keys($payload[0]));
        $this->assertNotContains('dash_url', array_keys($payload[0]));
        $this->assertNotContains('hls_url', array_keys($payload[0]));
        $this->assertNotContains('token', array_keys($payload[0]));
        $this->assertIsBool($payload[0]['isEnable']);
    }

    public function test_age_restriction_flag_changes_the_candidate_pool(): void
    {
        $this->insertMovie('regular', 'Regular', 'Drama', ['isMizo' => 1]);
        $this->insertMovie('restricted', 'Restricted', 'Drama', ['isMizo' => 1, 'isAgeRestricted' => 1]);

        $regular = app(AlsoLikeController::class)->alsoLike(Request::create('/', 'GET', [
            'movie_title' => 'no title match',
        ]))->getData(true);
        $withRestricted = app(AlsoLikeController::class)->alsoLike(Request::create('/', 'GET', [
            'movie_title' => 'no title match',
            'age_restriction' => 'true',
        ]))->getData(true);

        $this->assertSame(['regular'], array_column($regular, 'id'));
        $this->assertEqualsCanonicalizing(['regular', 'restricted'], array_column($withRestricted, 'id'));
    }

    public function test_model_changes_invalidate_the_shared_candidate_revision(): void
    {
        $this->insertMovie('visible', 'Visible title', 'Drama', ['isMizo' => 1]);
        $request = Request::create('/', 'GET', ['movie_title' => 'no title match']);

        $first = app(AlsoLikeController::class)->alsoLike($request)->getData(true);
        $revisionBefore = MovieCatalogRevision::current();
        $movie = MovieModel::where('id', 'visible')->firstOrFail();
        $movie->isEnable = false;
        $movie->save();
        $revisionAfter = MovieCatalogRevision::current();
        $second = app(AlsoLikeController::class)->alsoLike($request)->getData(true);

        $this->assertSame(['visible'], array_column($first, 'id'));
        $this->assertGreaterThan($revisionBefore, $revisionAfter);
        $this->assertSame([], $second);
    }

    public function test_v4_catalog_recommendation_resolves_numeric_movie_key(): void
    {
        $this->insertMovie('catalog-source', 'Catalog source', 'Drama', ['isMizo' => 1]);
        $num = DB::table('movie')->where('id', 'catalog-source')->value('num');

        $response = app(CatalogController::class)->recommendations(
            Request::create('/', 'GET'),
            (string) $num,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $response->getData(true));
    }

    public function test_warm_index_hydrates_only_selected_models_and_keeps_result_size_bounded(): void
    {
        for ($index = 0; $index < 250; $index++) {
            $this->insertMovie(
                'movie-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'Representative title '.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'Drama',
                ['num' => 100000 + $index, 'isMizo' => 1, 'isBollywood' => 1, 'create_date' => '2026-01-01'],
            );
        }

        $request = Request::create('/', 'GET', ['movie_title' => 'Representative title 010']);
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });
        $retrievedRows = 0;
        MovieModel::retrieved(function () use (&$retrievedRows): void {
            $retrievedRows++;
        });

        memory_reset_peak_usage();
        $legacyMemoryStart = memory_get_usage(false);
        $started = microtime(true);
        $legacyContent = $this->legacyRecommendationResponse($request);
        $legacyMilliseconds = (microtime(true) - $started) * 1000;
        $legacyQueries = $queryCount;
        $legacyHydrated = $retrievedRows;
        $legacyBytes = strlen($legacyContent);
        $legacyPeak = memory_get_peak_usage(false) - $legacyMemoryStart;

        Cache::flush();
        $queryCount = 0;
        $retrievedRows = 0;
        memory_reset_peak_usage();
        $coldMemoryStart = memory_get_usage(false);
        $started = microtime(true);
        $response = app(AlsoLikeController::class)->alsoLike($request);
        $coldMilliseconds = (microtime(true) - $started) * 1000;
        $coldQueries = $queryCount;
        $coldHydrated = $retrievedRows;
        $coldBytes = strlen($response->getContent());
        $coldPeak = memory_get_peak_usage(false) - $coldMemoryStart;

        $queryCount = 0;
        $retrievedRows = 0;
        memory_reset_peak_usage();
        $warmMemoryStart = memory_get_usage(false);
        $started = microtime(true);
        $warmResponse = app(AlsoLikeController::class)->alsoLike($request);
        $warmMilliseconds = (microtime(true) - $started) * 1000;
        $warmQueries = $queryCount;
        $warmHydrated = $retrievedRows;
        $warmPeak = memory_get_peak_usage(false) - $warmMemoryStart;

        $this->assertLessThanOrEqual(20, count($response->getData(true)));
        $this->assertSame($legacyBytes, $coldBytes);
        $this->assertSame($coldBytes, strlen($warmResponse->getContent()));
        $this->assertLessThanOrEqual(20, $coldHydrated);
        $this->assertLessThanOrEqual(20, $warmHydrated);
        $this->assertGreaterThan($warmQueries, $coldQueries);
        $this->assertSame(250, $legacyHydrated);

        fwrite(STDERR, sprintf(
            "Recommendation synthetic benchmark (250 rows): legacy %.2fms/%d queries/%d hydrated/%dB/%dB peak; cold %.2fms/%d queries/%d hydrated/%dB/%dB peak; warm %.2fms/%d queries/%d hydrated/%dB/%dB peak\n",
            $legacyMilliseconds,
            $legacyQueries,
            $legacyHydrated,
            $legacyBytes,
            $legacyPeak,
            $coldMilliseconds,
            $coldQueries,
            $coldHydrated,
            $coldBytes,
            $coldPeak,
            $warmMilliseconds,
            $warmQueries,
            $warmHydrated,
            strlen($warmResponse->getContent()),
            $warmPeak,
        ));
    }

    private function insertMovie(string $id, string $title, string $genre, array $attributes = []): void
    {
        DB::table('movie')->insert(array_merge([
            'id' => $id,
            'title' => $title,
            'genre' => $genre,
            'status' => 'Published',
            'isEnable' => true,
            'isAgeRestricted' => false,
            'isChildMode' => false,
            'isMizo' => false,
            'isBollywood' => false,
            'isHollywood' => false,
            'isKorean' => false,
            'isDocumentary' => false,
            'isSeason' => false,
        ], $attributes));
    }

    private function legacyRecommendationResponse(Request $request): string
    {
        $movies = MovieModel::where('isEnable', 1)->where('status', 'Published')
            ->where('isAgeRestricted', 0)->where('isMizo', 1)->get()->toArray();
        foreach ($movies as &$movie) {
            foreach (['isEnable', 'isAgeRestricted', 'isBollywood', 'isHollywood', 'isKorean', 'isMizo', 'isDocumentary', 'isPayPerView', 'isPremium', 'isSeason', 'isSubtitle', 'isChildMode'] as $field) {
                if (isset($movie[$field])) {
                    $movie[$field] = (bool) $movie[$field];
                }
            }
        }
        unset($movie);

        $title = (string) $request->query('movie_title');
        $match = null;
        $bestScore = 0;
        foreach ($movies as $movie) {
            similar_text(strtolower($title), strtolower(trim($movie['title'])), $score);
            if ($score > $bestScore) {
                $bestScore = $score;
                $match = $movie;
            }
        }

        if ($bestScore < 60 || $match === null) {
            shuffle($movies);
            $recommended = array_slice($movies, 0, 20);
        } else {
            $sequels = [];
            $related = [];
            foreach ($movies as $movie) {
                if ($movie['title'] === $match['title']) {
                    continue;
                }
                similar_text($match['title'], $movie['title'], $score);
                if ($score > 80) {
                    $sequels[$movie['id']] = $movie;
                }
                if (strpos($movie['genre'], $match['genre']) !== false) {
                    foreach (['isBollywood', 'isHollywood', 'isKorean', 'isMizo', 'isDocumentary', 'isSeason'] as $field) {
                        if (! empty($match[$field]) && ! empty($movie[$field])) {
                            $related[$movie['id']] = $movie;
                            break;
                        }
                    }
                }
            }
            shuffle($related);
            $unique = [];
            foreach (array_merge(array_values($sequels), array_values($related)) as $movie) {
                $unique[$movie['id']] = $movie;
            }
            if (count($unique) < 20) {
                shuffle($movies);
                foreach ($movies as $movie) {
                    if ($movie['title'] !== $match['title'] && ! isset($unique[$movie['id']])) {
                        $unique[$movie['id']] = $movie;
                    }
                    if (count($unique) >= 20) {
                        break;
                    }
                }
            }
            $recommended = array_slice(array_values($unique), 0, 20);
        }

        foreach ($recommended as &$movie) {
            foreach (['isProtected', 'isBollywood', 'isCompleted', 'isDocumentary', 'isDubbed', 'isEnable', 'isHollywood', 'isKorean', 'isMizo', 'isPayPerView', 'isPremium', 'isAgeRestricted', 'isSeason', 'isSubtitle', 'isChildMode'] as $field) {
                if (array_key_exists($field, $movie)) {
                    $movie[$field] = (bool) $movie[$field];
                }
            }
            if (array_key_exists('num', $movie)) {
                $movie['num'] = (int) $movie['num'];
            }
            if (array_key_exists('views', $movie)) {
                $movie['views'] = (int) $movie['views'];
            }
            unset($movie['url'], $movie['dash_url'], $movie['hls_url'], $movie['token']);
        }
        unset($movie);

        return json_encode(array_values($recommended), JSON_THROW_ON_ERROR);
    }
}
