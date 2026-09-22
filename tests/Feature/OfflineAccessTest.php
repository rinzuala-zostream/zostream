<?php

namespace Tests\Feature;

use App\Http\Controllers\HlsFolderController;
use App\Http\Controllers\New\MovieController;
use App\Http\Controllers\New\OfflineController;
use App\Models\New\Devices;
use App\Models\New\Plan;
use App\Models\New\Subscription;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class OfflineAccessTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = (string) config('database.default');
        config([
            'database.default' => 'offline_testing',
            'database.connections.offline_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('offline_testing');
        DB::reconnect('offline_testing');

        Schema::create('movie', function (Blueprint $table) {
            $table->increments('num');
            $table->string('id')->unique();
            $table->string('title')->nullable();
            $table->boolean('isPremium')->default(false);
            $table->boolean('isPayPerView')->default(false);
        });
        Schema::create('episodes', function (Blueprint $table) {
            $table->increments('num');
            $table->string('id')->unique();
            $table->string('season_id')->nullable();
            $table->string('title')->nullable();
            $table->boolean('isPremium')->default(false);
            $table->boolean('isPayPerView')->default(false);
            $table->timestamps();
        });
        Schema::create('n_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('device_type');
            $table->unsignedInteger('device_limit')->default(1);
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('duration_days')->default(30);
            $table->string('quality')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('n_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->unsignedBigInteger('plan_id');
            $table->dateTime('start_at')->nullable();
            $table->dateTime('end_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('renewed_by')->nullable();
            $table->timestamps();
        });
        Schema::create('n_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->string('user_id');
            $table->string('device_name')->nullable();
            $table->string('device_type');
            $table->string('device_token')->unique();
            $table->boolean('is_owner_device')->default(false);
            $table->dateTime('last_activity')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        $plan = Plan::create([
            'name' => 'Mobile',
            'device_type' => 'mobile',
            'device_limit' => 1,
            'price' => 199,
            'duration_days' => 30,
            'quality' => '1080p',
            'is_active' => true,
        ]);
        $subscription = Subscription::create([
            'user_id' => 'user-a',
            'plan_id' => $plan->id,
            'start_at' => now(),
            'end_at' => now()->addDays(30),
            'is_active' => true,
        ]);
        Devices::create([
            'subscription_id' => $subscription->id,
            'user_id' => 'user-a',
            'device_name' => 'iPhone',
            'device_type' => 'mobile',
            'device_token' => 'ios-device',
            'status' => 'active',
        ]);

        DB::table('movie')->insert([
            'id' => 'movie-1',
            'title' => 'Free movie',
            'isPremium' => false,
            'isPayPerView' => false,
        ]);
        DB::table('episodes')->insert([
            'id' => 'episode-1',
            'season_id' => 'season-1',
            'title' => 'Free episode',
            'isPremium' => false,
            'isPayPerView' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('offline_testing');
        config(['database.default' => $this->originalConnection]);

        parent::tearDown();
    }

    public function test_ios_offline_access_returns_an_existing_hls_url_without_rebuilding_it(): void
    {
        $movies = Mockery::mock(MovieController::class);
        $movies->shouldReceive('getLink')
            ->once()
            ->withArgs(fn (Request $request, string $id) => $request->query('type') === 'movie' && $id === 'movie-1')
            ->andReturn(response()->json([
                'status' => 'success',
                'links' => [
                    'url' => 'encrypted-dash-source',
                    'hls_url' => 'https://cdn.example.test/movie/master.m3u8',
                ],
            ]));

        $hls = Mockery::mock(HlsFolderController::class);
        $hls->shouldNotReceive('check');

        $response = (new OfflineController($hls, $movies))
            ->requestOffline($this->iosRequest('movie-1', 'movie', false));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'video_url' => 'https://cdn.example.test/movie/master.m3u8',
            'qualities' => [],
            'format' => 'hls',
            'content_id' => 'movie-1',
            'content_type' => 'movie',
            'max_quality' => 'FULL_HD',
        ], $response->getData(true));
    }

    public function test_ios_episode_offline_access_converts_the_episode_source_with_the_existing_hls_flow(): void
    {
        $movies = Mockery::mock(MovieController::class);
        $movies->shouldReceive('getLink')
            ->once()
            ->withArgs(fn (Request $request, string $id) => $request->query('type') === 'episode' && $id === 'episode-1')
            ->andReturn(response()->json([
                'status' => 'success',
                'links' => [
                    ['url' => 'encrypted-episode-dash-source'],
                ],
            ]));

        $hls = Mockery::mock(HlsFolderController::class);
        $hls->shouldReceive('check')
            ->once()
            ->withArgs(fn (Request $request) => $request->input('url') === 'encrypted-episode-dash-source')
            ->andReturn(response()->json([
                'status' => 'success',
                'data' => [
                    'stream_url' => 'https://cdn.example.test/episode/master.m3u8',
                ],
            ]));

        $response = (new OfflineController($hls, $movies))
            ->requestOffline($this->iosRequest('episode-1', 'episode', false));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('https://cdn.example.test/episode/master.m3u8', $response->getData(true)['video_url']);
        $this->assertSame('hls', $response->getData(true)['format']);
        $this->assertSame('episode', $response->getData(true)['content_type']);
    }

    public function test_dash_quality_parser_supports_namespaced_content_type_manifests(): void
    {
        $xml = simplexml_load_string(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <MPD xmlns="urn:mpeg:dash:schema:mpd:2011">
              <Period>
                <AdaptationSet contentType="audio">
                  <Representation id="audio-1" bandwidth="128000" />
                </AdaptationSet>
                <AdaptationSet contentType="video">
                  <Representation id="video-360" bandwidth="600000" width="640" height="360" />
                  <Representation id="video-720-low" bandwidth="1200000" width="1280" height="720" />
                  <Representation id="video-720-high" bandwidth="2400000" width="1280" height="720" />
                </AdaptationSet>
              </Period>
            </MPD>
            XML);

        $controller = new OfflineController(
            Mockery::mock(HlsFolderController::class),
            Mockery::mock(MovieController::class),
        );
        $method = new \ReflectionMethod($controller, 'parseDashQualities');

        $this->assertSame([
            [
                'label' => '360p',
                'height' => 360,
                'bitrate' => 600000,
                'rep_id' => 'video-360',
            ],
            [
                'label' => '720p',
                'height' => 720,
                'bitrate' => 2400000,
                'rep_id' => 'video-720-high',
            ],
        ], $method->invoke($controller, $xml));
    }

    public function test_premium_offline_access_still_requires_a_subscription(): void
    {
        DB::table('movie')->insert([
            'id' => 'premium-movie',
            'title' => 'Premium movie',
            'isPremium' => true,
            'isPayPerView' => false,
        ]);

        $movies = Mockery::mock(MovieController::class);
        $movies->shouldNotReceive('getLink');

        $response = (new OfflineController(
            Mockery::mock(HlsFolderController::class),
            $movies,
        ))->requestOffline($this->iosRequest('premium-movie', 'movie', false));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Subscription Required', $response->getData(true)['title']);
    }

    private function iosRequest(string $contentId, string $contentType, bool $withSubscription = true): Request
    {
        return Request::create('/api/v4/offline/access', 'GET', [
            'movie_id' => $contentId,
            'movie_type' => $contentType,
            'subscription_id' => $withSubscription ? Subscription::query()->value('id') : null,
            'device_token' => 'ios-device',
            'user_id' => 'user-a',
            'platform' => 'ios',
        ]);
    }
}
