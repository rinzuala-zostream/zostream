<?php

namespace Tests\Feature;

use App\Http\Controllers\FCMNotificationController;
use App\Http\Controllers\New\MovieController;
use App\Models\MovieModel;
use App\Support\MpdDurationExtractor;
use App\Support\WebpImageUploader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class HomeSeriesOrderingTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = (string) config('database.default');
        config([
            'database.default' => 'home_series_ordering_testing',
            'cache.default' => 'array',
            'database.connections.home_series_ordering_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('home_series_ordering_testing');
        DB::reconnect('home_series_ordering_testing');
        Cache::store('array')->flush();

        Schema::create('movie_catalog_revisions', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(1);
        });
        DB::table('movie_catalog_revisions')->insert(['id' => 1, 'revision' => 1]);

        Schema::create('movie', function (Blueprint $table) {
            $table->increments('num');
            $table->string('id')->unique();
            $table->string('title');
            $table->date('create_date')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->date('release_on')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->string('genre')->nullable();
            $table->string('status')->default('Published');
            foreach (['isEnable', 'isMizo', 'isSeason', 'isPayPerView', 'isKorean', 'isHollywood', 'isBollywood', 'isDocumentary', 'isPremium', 'isAgeRestricted', 'isChildMode'] as $column) {
                $table->boolean($column)->default(false);
            }
        });
        Schema::create('trailers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('movie_id')->unique();
            $table->text('url');
            $table->timestamps();
        });
        Schema::create('wist_list', function (Blueprint $table) {
            $table->id();
            $table->string('uid');
            $table->string('movie_id');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('seasons', function (Blueprint $table) {
            $table->increments('num');
            $table->string('id')->unique();
            $table->unsignedInteger('movie_id');
            $table->date('release_date')->nullable();
            $table->string('status')->default('Draft');
            $table->timestamps();
        });
        Schema::create('episodes', function (Blueprint $table) {
            $table->increments('num');
            $table->string('id')->unique();
            $table->string('season_id');
            $table->date('release_date')->nullable();
            $table->string('status')->default('Draft');
            $table->timestamps();
        });

        $this->insertSeries(1, 'older-series', 'Older Series', '2025-01-01');
        $this->insertSeries(2, 'newer-series', 'Newer Series', '2026-01-01');

        DB::table('seasons')->insert([
            ['id' => 'older-season', 'movie_id' => 1, 'release_date' => '2025-01-01', 'status' => 'Published', 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00'],
            ['id' => 'newer-season', 'movie_id' => 2, 'release_date' => '2026-01-01', 'status' => 'Published', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        ]);
        DB::table('episodes')->insert([
            ['id' => 'older-series-new-episode', 'season_id' => 'older-season', 'release_date' => '2026-08-20', 'status' => 'Published', 'created_at' => '2026-08-20 21:30:00', 'updated_at' => '2026-08-20 21:30:00'],
            ['id' => 'newer-series-old-episode', 'season_id' => 'newer-season', 'release_date' => '2026-08-20', 'status' => 'Published', 'created_at' => '2026-08-20 20:15:00', 'updated_at' => '2026-08-20 20:15:00'],
            ['id' => 'newer-series-draft-episode', 'season_id' => 'newer-season', 'release_date' => '2026-09-01', 'status' => 'Draft', 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('home_series_ordering_testing');
        config(['database.default' => $this->originalConnection]);

        parent::tearDown();
    }

    public function test_home_series_keep_their_original_movie_order(): void
    {
        $data = $this->controller()->getMovies(new Request())->getData(true);

        $this->assertSame(['newer-series', 'older-series'], array_column($data['Series'], 'id'));
    }

    public function test_latest_update_falls_back_to_movie_create_date(): void
    {
        $data = $this->controller()->getMovies(new Request())->getData(true);

        $this->assertSame(['newer-series', 'older-series'], array_column($data['Latest Update'], 'id'));
    }

    public function test_movie_update_timestamp_takes_priority_over_create_date(): void
    {
        DB::table('movie')->where('id', 'older-series')->update(['updated_at' => now()]);

        $data = $this->controller()->getMovies(new Request())->getData(true);

        $this->assertNotNull(DB::table('movie')->where('id', 'older-series')->value('updated_at'));
        $this->assertSame(['older-series', 'newer-series'], array_column($data['Latest Update'], 'id'));
    }

    public function test_view_count_increment_does_not_change_movie_update_timestamp(): void
    {
        $timestamp = '2026-08-19 10:00:00';
        DB::table('movie')->where('id', 'newer-series')->update(['updated_at' => $timestamp]);

        MovieModel::where('id', 'newer-series')->firstOrFail()->increment('views');

        $this->assertSame($timestamp, DB::table('movie')->where('id', 'newer-series')->value('updated_at'));
    }

    public function test_other_home_categories_prioritize_movie_update_timestamp(): void
    {
        DB::table('movie')->where('id', 'older-series')->update(['updated_at' => now()]);

        $data = $this->controller()->getMovies(new Request())->getData(true);

        $this->assertSame(['older-series', 'newer-series'], array_column($data['Mizo'], 'id'));
        $this->assertSame(['older-series', 'newer-series'], array_column($data['Series'], 'id'));
        $this->assertSame(['older-series', 'newer-series'], array_column($data['New Release'], 'id'));
    }

    public function test_other_home_categories_fall_back_to_create_date_when_updated_at_is_empty(): void
    {
        DB::table('movie')->where('id', 'older-series')->update(['create_date' => '2027-01-01']);

        $data = $this->controller()->getMovies(new Request())->getData(true);

        $this->assertSame(['older-series', 'newer-series'], array_column($data['Mizo'], 'id'));
        $this->assertSame(['older-series', 'newer-series'], array_column($data['Series'], 'id'));
        $this->assertSame(['older-series', 'newer-series'], array_column($data['New Release'], 'id'));
    }

    public function test_latest_update_is_first_and_most_watched_is_hidden(): void
    {
        $data = $this->controller()->getMovies(new Request())->getData(true);

        $this->assertSame('Latest Update', array_key_first($data));
        $this->assertArrayNotHasKey('Most Watched', $data);
        $this->assertSame(['newer-series', 'older-series'], array_column($data['New Release'], 'id'));
    }

    public function test_home_includes_only_movies_with_a_non_empty_trailer_in_trailer_section(): void
    {
        DB::table('trailers')->insert([
            ['movie_id' => 2, 'url' => 'https://example.com/trailer.m3u8', 'created_at' => now(), 'updated_at' => now()],
            ['movie_id' => 1, 'url' => '   ', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $data = $this->controller()->getMovies(new Request())->getData(true);

        $this->assertSame(['newer-series'], array_column($data['Trailer'], 'id'));
        $this->assertSame('https://example.com/trailer.m3u8', $data['Trailer'][0]['trailer']);
        $this->assertSame(['Latest Update', 'Trailer'], array_slice(array_keys($data), 0, 2));
    }

    public function test_trailer_category_supports_view_all_requests(): void
    {
        DB::table('trailers')->insert([
            'movie_id' => 1,
            'url' => 'https://example.com/older-trailer.m3u8',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/movies/home', 'GET', ['category' => 'trailer']);
        $data = $this->controller()->getMovies($request)->getData(true);

        $this->assertSame(['older-series'], array_column($data, 'id'));
    }

    public function test_series_category_prioritizes_movie_update_timestamp(): void
    {
        DB::table('movie')->where('id', 'older-series')->update(['updated_at' => now()]);

        $request = Request::create('/movies/home', 'GET', ['category' => 'series']);
        $data = $this->controller()->getMovies($request)->getData(true);

        $this->assertSame(['older-series', 'newer-series'], array_column($data, 'id'));
    }

    public function test_view_all_category_keeps_its_original_movie_order(): void
    {
        $request = Request::create('/movies/home', 'GET', ['category' => 'mizo']);
        $data = $this->controller()->getMovies($request)->getData(true);

        $this->assertSame(['newer-series', 'older-series'], array_column($data, 'id'));
    }

    public function test_shared_home_cache_is_invalidated_by_movie_create_update_disable_and_delete(): void
    {
        $controller = $this->controller();
        $request = new Request();

        $created = MovieModel::create([
            'id' => 'new-movie',
            'title' => 'New movie',
            'create_date' => '2026-08-20',
            'release_on' => '2026-08-20',
            'status' => 'Published',
            'isEnable' => true,
            'isMizo' => true,
        ]);

        $first = $controller->getMovies($request)->getData(true);
        $this->assertSame('New movie', $first['Latest Update'][0]['title']);

        $created->update(['title' => 'Renamed movie']);
        $renamed = $controller->getMovies($request)->getData(true);
        $this->assertSame('Renamed movie', $renamed['Latest Update'][0]['title']);

        $created->update(['isEnable' => false]);
        $disabled = $controller->getMovies($request)->getData(true);
        $this->assertNotContains('new-movie', array_column($disabled['Latest Update'], 'id'));

        $created->delete();
        $deleted = $controller->getMovies($request)->getData(true);
        $this->assertNotContains('new-movie', array_column($deleted['Latest Update'], 'id'));
    }

    public function test_shared_catalog_cache_keeps_watch_lists_private_and_visibility_dimensions_separate(): void
    {
        DB::table('movie')->where('id', 'older-series')->update(['isMizo' => false, 'isChildMode' => true]);
        DB::table('wist_list')->insert([
            ['uid' => 'user-a', 'movie_id' => 'newer-series', 'created_at' => '2026-01-02 00:00:00'],
            ['uid' => 'user-a', 'movie_id' => 'older-series', 'created_at' => '2026-01-01 00:00:00'],
        ]);
        $controller = $this->controller();

        $userA = $controller->getMovies(Request::create('/movies/home', 'GET', ['user_id' => 'user-a']))->getData(true);
        $watchListQueries = 0;
        DB::listen(function ($query) use (&$watchListQueries): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $watchListQueries++;
            }
        });
        $userAAgain = $controller->getMovies(Request::create('/movies/home', 'GET', ['user_id' => 'user-a']))->getData(true);
        $repeatWatchListQueries = $watchListQueries;
        $userB = $controller->getMovies(Request::create('/movies/home', 'GET', ['user_id' => 'user-b']))->getData(true);
        $mizoOnly = $controller->getMovies(new Request())->getData(true);
        $kids = $controller->getMovies(Request::create('/movies/home', 'GET', [
            'user_id' => 'user-b',
            'isChildMode' => 'true',
        ]))->getData(true);

        $this->assertSame(['Newer Series', 'Older Series'], array_column($userA['Watch List'], 'title'));
        $this->assertSame($userA['Watch List'], $userAAgain['Watch List']);
        $this->assertSame(3, $repeatWatchListQueries); // revision, one watch-list query and one eager trailer query
        $this->assertArrayNotHasKey('Watch List', $userB);
        $this->assertContains('older-series', array_column($userA['Latest Update'], 'id'));
        $this->assertSame(['newer-series'], array_column($mizoOnly['Latest Update'], 'id'));
        $this->assertSame(['older-series'], array_column($kids['Latest Update'], 'id'));
    }

    public function test_home_cache_hit_avoids_reloading_each_catalog_section(): void
    {
        $controller = $this->controller();
        $request = new Request();
        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $queryCount++;
            }
        });

        $coldResponse = $controller->getMovies($request);
        $coldQueryCount = $queryCount;
        $queryCount = 0;
        $warmResponse = $controller->getMovies($request);
        $rows = array_sum(array_map('count', $warmResponse->getData(true)));

        $this->assertGreaterThan(1, $coldQueryCount);
        $this->assertSame(1, $queryCount); // catalog revision lookup; sections came from shared cache
        $this->assertSame(10, $rows);
        $this->assertSame(5234, strlen($warmResponse->getContent()));
        $this->assertSame($coldResponse->getContent(), $warmResponse->getContent());
    }

    public function test_trailer_admin_update_invalidates_cached_home_sections(): void
    {
        $controller = $this->controller();
        $request = new Request();
        $initial = $controller->getMovies($request)->getData(true);
        $this->assertArrayNotHasKey('Trailer', $initial);

        $controller->updateTrailer(
            Request::create('/admin/catalog/items/newer-series/trailer', 'PUT', [
                'trailer' => 'https://example.com/new-trailer.m3u8',
            ]),
            'newer-series',
        );

        $updated = $controller->getMovies($request)->getData(true);
        $this->assertSame('https://example.com/new-trailer.m3u8', $updated['Trailer'][0]['trailer']);
    }

    public function test_age_restriction_request_dimension_does_not_reuse_the_unrestricted_home_payload(): void
    {
        MovieModel::create([
            'id' => 'age-restricted-movie',
            'title' => 'Restricted title',
            'create_date' => '2026-08-21',
            'release_on' => '2026-08-21',
            'status' => 'Published',
            'isEnable' => true,
            'isMizo' => true,
            'isAgeRestricted' => true,
        ]);

        $controller = $this->controller();
        $ordinary = $controller->getMovies(new Request())->getData(true);
        $includeRestricted = $controller->getMovies(Request::create('/movies/home', 'GET', [
            'age_restriction' => 'true',
        ]))->getData(true);

        $this->assertArrayNotHasKey('18+', $ordinary);
        $this->assertSame(['age-restricted-movie'], array_column($includeRestricted['18+'], 'id'));
    }

    public function test_catalog_cache_dimensions_include_category_and_platform(): void
    {
        $cache = app(\App\Support\Catalog\PublicMovieCatalogCache::class);
        $ios = Request::create('/movies/home', 'GET', [
            'category' => 'series',
            'platform' => 'ios',
        ]);
        $ios->headers->set('X-Platform', 'ios');
        $web = Request::create('/movies/home', 'GET', [
            'category' => 'series',
            'platform' => 'web',
        ]);
        $web->headers->set('X-Platform', 'web');

        $iosDimensions = $cache->dimensions('v2-home', $ios, false, false, false, true);
        $webDimensions = $cache->dimensions('v2-home', $web, false, false, false, true);

        $this->assertSame('series', $iosDimensions['category']);
        $this->assertNotSame($iosDimensions, $webDimensions);
        $this->assertSame('ios', $iosDimensions['platform_header']);
    }

    public function test_legacy_movie_listing_uses_shared_revision_cache_and_trailer_eager_load(): void
    {
        config(['app.api_key' => 'catalog-test-key']);
        $controller = new \App\Http\Controllers\MovieController(
            Mockery::mock(FCMNotificationController::class),
        );
        $request = Request::create('/movies', 'GET');
        $request->headers->set('X-Api-Key', 'catalog-test-key');

        $first = $controller->getMovies($request)->getData(true);
        MovieModel::where('id', 'newer-series')->firstOrFail()->update(['title' => 'Fresh title']);
        $second = $controller->getMovies($request)->getData(true);

        $this->assertSame('Fresh title', $second['Latest Update'][0]['title']);
        $this->assertSame(['newer-series', 'older-series'], array_column($first['Latest Update'], 'id'));
    }

    public function test_genre_listing_keeps_legacy_token_matching_while_hydrating_only_the_page(): void
    {
        DB::table('movie')->where('id', 'older-series')->update(['genre' => 'Drama|Action']);
        DB::table('movie')->where('id', 'newer-series')->update(['genre' => 'Comedy/Drama']);
        $controller = $this->controller();

        $anyMatch = $controller->genre(Request::create('/movies/genre', 'GET', [
            'genre' => 'drama',
            'per_page' => 1,
            'sort_by' => 'num',
            'sort_dir' => 'asc',
        ]))->getData(true);
        $allMatch = $controller->genre(Request::create('/movies/genre', 'GET', [
            'genres' => ['Drama', 'Action'],
            'match' => 'all',
            'sort_by' => 'num',
            'sort_dir' => 'asc',
        ]))->getData(true);

        $this->assertSame(2, $anyMatch['pagination']['total']);
        $this->assertSame(['older-series'], array_column($anyMatch['data'], 'id'));
        $this->assertSame(['older-series'], array_column($allMatch['data'], 'id'));
    }

    private function insertSeries(int $num, string $id, string $title, string $createDate): void
    {
        DB::table('movie')->insert([
            'num' => $num,
            'id' => $id,
            'title' => $title,
            'create_date' => $createDate,
            'release_on' => $createDate,
            'views' => $num * 100,
            'status' => 'Published',
            'isEnable' => true,
            'isMizo' => true,
            'isSeason' => true,
        ]);
    }

    private function controller(): MovieController
    {
        return new MovieController(
            Mockery::mock(WebpImageUploader::class),
            Mockery::mock(MpdDurationExtractor::class),
            Mockery::mock(FCMNotificationController::class),
        );
    }
}
