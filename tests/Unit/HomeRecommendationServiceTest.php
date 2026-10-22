<?php

namespace Tests\Unit;

use App\Services\HomeRecommendationService;
use App\Services\LiveHomeSectionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class HomeRecommendationServiceTest extends TestCase
{
    public function test_persistent_service_receives_current_user_signals(): void
    {
        config()->set('recommender.service_url', 'http://127.0.0.1:8765');
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:8765/*' => Http::response(['history_size' => 1, 'top_picks_for_you' => []])]);
        $service = new HomeRecommendationService(Mockery::mock(LiveHomeSectionService::class));
        $run = new \ReflectionMethod($service, 'run');
        $signals = ['watch_position' => [['movie_id' => 'one']], 'wishlist' => []];
        $result = $run->invoke($service, 'user-one', 11, 'kids', false, '/missing/script', '/missing/model', $signals);
        $this->assertSame(1, $result['history_size']);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:8765/homepage'
            && $request['user_id'] === 'user-one'
            && $request['signals'] === $signals
            && $request['mode'] === 'kids'
            && $request['include_age_restricted'] === false);
        Http::assertSentCount(1);
    }

    public function test_service_failure_serves_live_sections_without_spawning_python(): void
    {
        config()->set('recommender.response_cache_fresh_seconds', 0);
        config()->set('recommender.cache_seconds', 0);
        config()->set('recommender.service_url', 'http://127.0.0.1:8765');
        config()->set('recommender.script', __FILE__);
        config()->set('recommender.model', __FILE__);
        config()->set('recommender.python', '/must-not-execute');
        Http::fake(['127.0.0.1:8765/*' => Http::response([], 503)]);
        $live = Mockery::mock(LiveHomeSectionService::class);
        $live->shouldReceive('snapshot')->once()->andReturn([
            'signals' => ['watch_position' => [], 'wishlist' => []],
            'sections' => ['trending_now' => []], 'version' => 'test',
        ]);
        $live->shouldReceive('filterForUser')->once()->andReturnUsing(fn (array $payload): array => $payload);
        $result = (new HomeRecommendationService($live))->homepage('user-one', 11);
        $this->assertSame([], $result['top_picks_for_you']);
        $this->assertSame([], $result['trending_now']);
        Http::assertSentCount(1);
    }

    public function test_catalog_metadata_is_shared_between_users_and_expires(): void
    {
        Cache::flush();
        config()->set('recommender.response_cache_fresh_seconds', 0);
        config()->set('recommender.metadata_cache_seconds', 60);
        Schema::create('movie', function (Blueprint $table): void {
            $table->increments('num');
            $table->string('id');
        });
        Schema::create('seasons', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('movie_id');
            $table->integer('season_number');
            $table->string('title');
            $table->boolean('isPayPerView');
        });
        Schema::create('episodes', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('season_id');
            $table->boolean('isPayPerView');
        });
        DB::table('movie')->insert([['num' => 1, 'id' => 'movie-1'], ['num' => 2, 'id' => 'movie-2']]);
        DB::table('seasons')->insert([
            'id' => 1, 'movie_id' => 1, 'season_number' => 1,
            'title' => 'Season 1', 'isPayPerView' => false,
        ]);
        DB::table('episodes')->insert(['season_id' => 1, 'isPayPerView' => true]);
        $live = Mockery::mock(LiveHomeSectionService::class);
        $live->shouldReceive('snapshot')->andReturnUsing(fn (string $user): array => [
            'signals' => [],
            'sections' => ['trending_now' => $user === 'first'
                ? [['id' => 'movie-1'], ['id' => 'movie-2']]
                : [['id' => 'movie-2'], ['id' => 'movie-1']]],
        ]);
        $live->shouldReceive('filterForUser')->andReturnUsing(fn (array $payload): array => $payload);
        $service = new HomeRecommendationService($live);

        try {
            $first = $service->homepage('first', 11, 'adult', false, ['trending_now']);
            $this->assertSame(1, $first['trending_now'][0]['ppv_seasons'][0]['ppv_episode_count']);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $second = $service->homepage('second', 11, 'adult', false, ['trending_now']);
            $this->assertSame([], DB::getQueryLog());
            $this->assertSame([], $second['trending_now'][0]['ppv_seasons']);
            $this->assertSame($first['trending_now'][0]['ppv_seasons'], $second['trending_now'][1]['ppv_seasons']);

            DB::table('episodes')->insert(['season_id' => 1, 'isPayPerView' => true]);
            $this->travel(61)->seconds();
            $refreshed = $service->homepage('first', 11, 'adult', false, ['trending_now']);
            $this->assertSame(2, $refreshed['trending_now'][0]['ppv_seasons'][0]['ppv_episode_count']);
        } finally {
            $this->travelBack();
            DB::disableQueryLog();
            Schema::drop('episodes');
            Schema::drop('seasons');
            Schema::drop('movie');
        }
    }

    public function test_live_only_request_skips_ai_and_unrequested_sections(): void
    {
        $createdMovieTable = false;
        if (! Schema::hasTable('movie')) {
            Schema::create('movie', function (Blueprint $table): void {
                $table->increments('num');
                $table->string('id')->unique();
            });
            $createdMovieTable = true;
        }

        Cache::flush();
        config()->set('recommender.response_cache_fresh_seconds', 30);
        config()->set('recommender.response_cache_stale_seconds', 300);

        $liveSections = Mockery::mock(LiveHomeSectionService::class);
        $liveSections->shouldReceive('snapshot')
            ->once()
            ->with('trusted-user', 11, 'adult', false, ['trending_now'], false)
            ->andReturn([
                'signals' => ['watch_position' => [], 'wishlist' => []],
                'sections' => [
                    'trending_now' => [
                        ['id' => 'movie-1', 'title' => 'Movie 1'],
                    ],
                ],
                'version' => 'unused-for-live-only',
            ]);
        $liveSections->shouldNotReceive('filterAiSections');
        $liveSections->shouldReceive('filterForUser')
            ->once()
            ->with(Mockery::type('array'), 'trusted-user')
            ->andReturnUsing(fn (array $payload): array => $payload);

        config()->set('recommender.script', '/definitely/missing/recommender.py');
        config()->set('recommender.model', '/definitely/missing/model.json.gz');

        $result = (new HomeRecommendationService($liveSections))->homepage(
            'trusted-user',
            11,
            'adult',
            false,
            ['trending_now']
        );
        $cachedResult = (new HomeRecommendationService($liveSections))->homepage(
            'trusted-user',
            11,
            'adult',
            false,
            ['trending_now']
        );

        $this->assertSame('movie-1', $result['trending_now'][0]['id']);
        $this->assertSame([], $result['top_picks_for_you']);
        $this->assertSame($result, $cachedResult);

        if ($createdMovieTable) {
            Schema::drop('movie');
        }
    }
}
