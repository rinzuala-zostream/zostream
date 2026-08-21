<?php

namespace Tests\Feature;

use App\Http\Controllers\FCMNotificationController;
use App\Http\Controllers\New\MovieController;
use App\Support\MpdDurationExtractor;
use App\Support\WebpImageUploader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'database.connections.home_series_ordering_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('home_series_ordering_testing');
        DB::reconnect('home_series_ordering_testing');

        Schema::create('movie', function (Blueprint $table) {
            $table->increments('num');
            $table->string('id')->unique();
            $table->string('title');
            $table->date('create_date')->nullable();
            $table->date('release_on')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->string('genre')->nullable();
            $table->string('status')->default('Published');
            foreach (['isEnable', 'isMizo', 'isSeason', 'isPayPerView', 'isKorean', 'isHollywood', 'isBollywood', 'isDocumentary', 'isPremium', 'isAgeRestricted', 'isChildMode'] as $column) {
                $table->boolean($column)->default(false);
            }
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
            ['id' => 'older-series-new-episode', 'season_id' => 'older-season', 'release_date' => '2026-08-20', 'status' => 'Published', 'created_at' => '2026-08-20 00:00:00', 'updated_at' => '2026-08-20 00:00:00'],
            ['id' => 'newer-series-old-episode', 'season_id' => 'newer-season', 'release_date' => '2026-07-01', 'status' => 'Published', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00'],
            ['id' => 'newer-series-draft-episode', 'season_id' => 'newer-season', 'release_date' => '2026-09-01', 'status' => 'Draft', 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('home_series_ordering_testing');
        config(['database.default' => $this->originalConnection]);

        parent::tearDown();
    }

    public function test_home_series_are_ordered_by_latest_published_episode(): void
    {
        $data = $this->controller()->getMovies(new Request())->getData(true);

        $this->assertSame(['older-series', 'newer-series'], array_column($data['Series'], 'id'));
    }

    public function test_latest_update_row_includes_newly_published_series_episodes(): void
    {
        $data = $this->controller()->getMovies(new Request())->getData(true);

        $this->assertSame(['older-series', 'newer-series'], array_column($data['Latest Update'], 'id'));
    }

    public function test_category_rows_use_latest_published_content_order(): void
    {
        $data = $this->controller()->getMovies(new Request())->getData(true);

        $this->assertSame(['older-series', 'newer-series'], array_column($data['Mizo'], 'id'));
    }

    public function test_new_release_and_most_watched_keep_their_specific_ordering(): void
    {
        $data = $this->controller()->getMovies(new Request())->getData(true);

        $this->assertSame(['newer-series', 'older-series'], array_column($data['New Release'], 'id'));
        $this->assertSame(['newer-series', 'older-series'], array_column($data['Most Watched'], 'id'));
    }

    public function test_series_category_uses_the_same_latest_content_order(): void
    {
        $request = Request::create('/movies/home', 'GET', ['category' => 'series']);
        $data = $this->controller()->getMovies($request)->getData(true);

        $this->assertSame(['older-series', 'newer-series'], array_column($data, 'id'));
    }

    public function test_view_all_category_uses_latest_published_content_order(): void
    {
        $request = Request::create('/movies/home', 'GET', ['category' => 'mizo']);
        $data = $this->controller()->getMovies($request)->getData(true);

        $this->assertSame(['older-series', 'newer-series'], array_column($data, 'id'));
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
