<?php

namespace Tests\Unit;

use App\Services\LiveHomeSectionService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LiveHomeSectionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'live-home-section-testing');
        config()->set('database.connections.live-home-section-testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('live-home-section-testing');
        Cache::flush();

        Schema::create('movie', function (Blueprint $table): void {
            $table->increments('num');
            $table->string('id')->unique();
            $table->string('title');
            $table->string('genre')->nullable();
            $table->string('poster')->nullable();
            $table->string('cover_img')->nullable();
            $table->boolean('isPremium')->default(false);
            $table->boolean('isPayPerView')->default(false);
            $table->boolean('isAgeRestricted')->default(false);
            $table->boolean('isChildMode')->default(false);
            $table->boolean('isMizo')->default(false);
            $table->boolean('isEnable')->default(true);
            $table->string('status')->default('Published');
            $table->date('release_on')->nullable();
        });
        Schema::create('seasons', function (Blueprint $table): void {
            $table->increments('num');
            $table->string('id')->unique();
            $table->unsignedInteger('movie_id');
        });
        Schema::create('episodes', function (Blueprint $table): void {
            $table->increments('num');
            $table->string('id')->unique();
            $table->string('season_id');
        });
        Schema::create('watch_position', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id');
            $table->string('movie_id');
            $table->string('movie_type')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Carbon::setTestNow('2026-09-29 12:00:00');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Carbon::setTestNow();
        DB::disconnect('live-home-section-testing');

        parent::tearDown();
    }

    public function test_last_month_top_ten_ranks_movies_and_rolls_episodes_up_to_their_series(): void
    {
        DB::table('movie')->insert([
            ['num' => 1, 'id' => 'movie-a', 'title' => 'Movie A'],
            ['num' => 2, 'id' => 'series-b', 'title' => 'Series B'],
            ['num' => 3, 'id' => 'movie-current', 'title' => 'Current Month Movie'],
        ]);
        DB::table('seasons')->insert(['id' => 'season-b', 'movie_id' => 2]);
        DB::table('episodes')->insert(['id' => 'episode-b', 'season_id' => 'season-b']);
        DB::table('watch_position')->insert([
            ['user_id' => 'u1', 'movie_id' => 'movie-a', 'movie_type' => 'movie', 'updated_at' => '2026-08-02 10:00:00'],
            ['user_id' => 'u2', 'movie_id' => 'movie-a', 'movie_type' => null, 'updated_at' => '2026-08-20 10:00:00'],
            ['user_id' => 'u3', 'movie_id' => 'episode-b', 'movie_type' => 'episode', 'updated_at' => '2026-08-05 10:00:00'],
            ['user_id' => 'u4', 'movie_id' => 'episode-b', 'movie_type' => 'EPISODE', 'updated_at' => '2026-08-25 10:00:00'],
            ['user_id' => 'u5', 'movie_id' => 'episode-b', 'movie_type' => 'episode', 'updated_at' => '2026-08-28 10:00:00'],
            ['user_id' => 'u6', 'movie_id' => 'movie-current', 'movie_type' => 'movie', 'updated_at' => '2026-09-01 00:00:00'],
        ]);

        $this->artisan('home:warm-monthly-top-ten')->assertSuccessful();

        $snapshot = app(LiveHomeSectionService::class)->snapshot(
            'trusted-user',
            50,
            'adult',
            false,
            ['last_month_top_10'],
            false
        );

        $items = $snapshot['sections']['last_month_top_10'];

        $this->assertSame(['series-b', 'movie-a'], array_column($items, 'id'));
        $this->assertSame([3, 2], array_column($items, 'monthly_views'));
        $this->assertCount(2, $items);
    }

    public function test_cold_home_request_never_queries_watch_history(): void
    {
        DB::enableQueryLog();
        $snapshot = app(LiveHomeSectionService::class)->snapshot(
            'user', 10, 'adult', false, ['last_month_top_10'], false
        );

        $this->assertSame([], $snapshot['sections']['last_month_top_10']);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_warmed_rankings_survive_hourly_expiry_and_remain_isolated_by_audience_and_month(): void
    {
        DB::table('movie')->insert([
            ['id' => 'family', 'title' => 'Family', 'isChildMode' => true, 'isAgeRestricted' => false],
            ['id' => 'adult', 'title' => 'Adult', 'isChildMode' => false, 'isAgeRestricted' => true],
        ]);
        foreach (['family', 'adult'] as $id) {
            DB::table('watch_position')->insert([
                'user_id' => 'user', 'movie_id' => $id, 'movie_type' => 'movie',
                'updated_at' => '2026-08-01 00:00:00',
            ]);
        }
        $service = app(LiveHomeSectionService::class);
        $service->warmLastMonthTopTen();
        Carbon::setTestNow('2026-09-29 14:00:00');
        Schema::drop('watch_position');

        // Failed background refreshes must not replace the last good result.
        try {
            $service->warmLastMonthTopTen();
            $this->fail('Expected the refresh to fail without watch history.');
        } catch (\Illuminate\Database\QueryException $exception) {
            // The request below must still serve the existing cache.
        }

        DB::enableQueryLog();
        foreach ([['adult', false, ['family']], ['adult', true, ['adult', 'family']], ['kids', false, ['family']], ['kids', true, ['family']]] as [$mode, $restricted, $ids]) {
            $snapshot = $service->snapshot('another-user', 50, $mode, $restricted, ['last_month_top_10'], false);
            $this->assertSame($ids, array_column($snapshot['sections']['last_month_top_10'], 'id'));
        }
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();

        Carbon::setTestNow('2026-10-01 00:00:00');
        $snapshot = $service->snapshot('user', 10, 'adult', false, ['last_month_top_10'], false);
        $this->assertSame([], $snapshot['sections']['last_month_top_10']);
    }

    public function test_last_month_top_ten_failure_does_not_break_the_home_snapshot(): void
    {
        Cache::flush();
        Schema::drop('watch_position');

        $snapshot = app(LiveHomeSectionService::class)->snapshot(
            'trusted-user',
            11,
            'adult',
            false,
            ['last_month_top_10'],
            false
        );

        $this->assertSame([], $snapshot['sections']['last_month_top_10']);
    }

    public function test_special_user_home_payload_only_keeps_mizo_movies_and_series(): void
    {
        DB::table('movie')->insert([
            ['num' => 1, 'id' => 'mizo-movie', 'title' => 'Mizo Movie', 'isMizo' => true],
            ['num' => 2, 'id' => 'other-movie', 'title' => 'Other Movie', 'isMizo' => false],
        ]);

        $payload = [
            'history_size' => 2,
            'top_picks_for_you' => [
                'anchor' => ['id' => 'other-movie'],
                'items' => [
                    ['id' => 'mizo-movie'],
                    ['id' => 'other-movie'],
                ],
            ],
            'continue_watching' => [
                ['id' => 'episode-1', 'parent_id' => 'mizo-movie'],
                ['id' => 'episode-2', 'parent_id' => 'other-movie'],
            ],
        ];

        $filtered = app(LiveHomeSectionService::class)->filterForUser(
            $payload,
            'AW7ovVnTdgWuvE1Uke7QTQ5OEQt1'
        );

        $this->assertNull($filtered['top_picks_for_you']['anchor']);
        $this->assertSame(['mizo-movie'], array_column($filtered['top_picks_for_you']['items'], 'id'));
        $this->assertSame(['episode-1'], array_column($filtered['continue_watching'], 'id'));
        $this->assertSame(2, $filtered['history_size']);
    }

    public function test_other_users_keep_the_original_home_payload(): void
    {
        $payload = ['trending_now' => [['id' => 'any-movie']]];

        $this->assertSame(
            $payload,
            app(LiveHomeSectionService::class)->filterForUser($payload, 'another-user')
        );
    }
}
