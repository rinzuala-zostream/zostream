<?php

namespace Tests\Feature;

use App\Http\Controllers\HlsFolderController;
use App\Http\Controllers\New\MovieController;
use App\Http\Controllers\New\OfflineController;
use App\Models\New\Devices;
use App\Models\New\PaymentHistory;
use App\Models\New\Plan;
use App\Models\New\Subscription;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
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
        Schema::create('n_payment_histories', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('movie_id')->nullable();
            $table->string('device_type');
            $table->string('app_payment_type');
            $table->string('status');
            $table->dateTime('expiry_date')->nullable();
            $table->timestamps();
        });
        Schema::create('offline_download_daily_quotas', function (Blueprint $table) {
            $table->id();
            $table->char('user_key', 64);
            $table->string('user_id', 191);
            $table->date('quota_date');
            $table->unsignedTinyInteger('downloads_count')->default(0);
            $table->timestamps();
            $table->unique(['user_key', 'quota_date']);
        });
        Schema::create('offline_download_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quota_id');
            $table->char('content_key', 64);
            $table->string('content_type', 16);
            $table->string('content_id', 191);
            $table->timestamps();
            $table->unique(['quota_id', 'content_key']);
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
        Carbon::setTestNow();
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
        $data = $response->getData(true);
        $this->assertSame('https://cdn.example.test/movie/master.m3u8', $data['video_url']);
        $this->assertSame([], $data['qualities']);
        $this->assertSame('hls', $data['format']);
        $this->assertSame('movie-1', $data['content_id']);
        $this->assertSame('movie', $data['content_type']);
        $this->assertSame('FULL_HD', $data['max_quality']);
        $this->assertSame('free', $data['access_type']);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, Carbon::parse($data['expires_at'])->timestamp, 5);
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

    public function test_android_episode_resolver_accepts_url_encoded_encrypted_sources(): void
    {
        $mpdUrl = 'https://cdn.example.test/Series Name/Season 1/Episode 1/manifest.mpd';
        $encrypted = $this->encryptOfflineSource($mpdUrl);
        $encoded = str_replace(
            ['+', '/', '='],
            ['%2B', '%2F', '%3D'],
            $encrypted,
        );

        $controller = new OfflineController(
            Mockery::mock(HlsFolderController::class),
            Mockery::mock(MovieController::class),
        );
        $method = new \ReflectionMethod($controller, 'resolveMpdUrl');
        $resolved = $method->invoke($controller, $encoded);

        $this->assertSame($mpdUrl, $resolved['url']);
        $this->assertSame('decrypted', $resolved['source']);
    }

    public function test_ios_hls_resolver_accepts_url_encoded_encrypted_episode_sources(): void
    {
        $mpdUrl = 'https://cdn.example.test/Series Name/Season 1/Episode 1/manifest.mpd';
        $encrypted = $this->encryptOfflineSource($mpdUrl);
        $encoded = str_replace(
            ['+', '/', '='],
            ['%2B', '%2F', '%3D'],
            $encrypted,
        );
        $hlsRoot = storage_path('framework/testing/offline-hls-'.bin2hex(random_bytes(4)));
        $episodeDirectory = $hlsRoot.'/Series Name/Season 1/Episode 1';
        File::ensureDirectoryExists($episodeDirectory);
        File::put($episodeDirectory.'/master.m3u8', "#EXTM3U\n#EXT-X-ENDLIST\n");
        touch($episodeDirectory.'/master.m3u8', time() + 3600);
        config(['streaming.hls_root' => $hlsRoot]);

        try {
            $response = (new HlsFolderController)->check(Request::create('', 'GET', [
                'url' => $encoded,
            ]));
            $data = $response->getData(true);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('success', $data['status']);
            $this->assertSame('decrypted', $data['data']['source']);
            $this->assertSame($mpdUrl, $data['data']['resolved_mpd']);
            $this->assertStringContainsString('/master.m3u8', $data['data']['stream_url']);
        } finally {
            File::deleteDirectory($hlsRoot);
        }
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

    public function test_kar_one_plan_must_upgrade_before_downloading_premium_content(): void
    {
        DB::table('movie')->insert([
            'id' => 'weekly-premium',
            'title' => 'Weekly premium movie',
            'isPremium' => true,
            'isPayPerView' => false,
        ]);
        Plan::query()->update(['name' => 'Kar 1']);

        $movies = Mockery::mock(MovieController::class);
        $movies->shouldNotReceive('getLink');

        $response = (new OfflineController(
            Mockery::mock(HlsFolderController::class),
            $movies,
        ))->requestOffline($this->iosRequest('weekly-premium', 'movie'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('OFFLINE_PLAN_UPGRADE_REQUIRED', $response->getData(true)['code']);
        $this->assertStringContainsString('Upgrade to Thla 1', $response->getData(true)['message']);
    }

    public function test_ppv_download_requires_a_rental_for_the_current_device_type_and_uses_rental_expiry(): void
    {
        DB::table('movie')->insert([
            'id' => 'ppv-movie',
            'title' => 'PPV movie',
            'isPremium' => false,
            'isPayPerView' => true,
        ]);
        $rentalExpiry = now()->addHours(12)->startOfSecond();
        PaymentHistory::create([
            'user_id' => 'user-a',
            'movie_id' => 'ppv-movie',
            'device_type' => 'tv',
            'app_payment_type' => 'ppv',
            'status' => 'success',
            'expiry_date' => $rentalExpiry,
        ]);

        $movies = Mockery::mock(MovieController::class);
        $movies->shouldNotReceive('getLink');
        $controller = new OfflineController(Mockery::mock(HlsFolderController::class), $movies);
        $denied = $controller->requestOffline($this->iosRequest('ppv-movie', 'movie', false));

        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame('Rental Required', $denied->getData(true)['title']);

        PaymentHistory::create([
            'user_id' => 'user-a',
            'movie_id' => 'ppv-movie',
            'device_type' => 'mobile',
            'app_payment_type' => 'ppv',
            'status' => 'success',
            'expiry_date' => $rentalExpiry,
        ]);
        $movies = Mockery::mock(MovieController::class);
        $movies->shouldReceive('getLink')->once()->andReturn(response()->json([
            'status' => 'success',
            'links' => ['hls_url' => 'https://cdn.example.test/ppv/master.m3u8'],
        ]));
        $controller = new OfflineController(Mockery::mock(HlsFolderController::class), $movies);
        $allowed = $controller->requestOffline($this->iosRequest('ppv-movie', 'movie', false));
        $data = $allowed->getData(true);

        $this->assertSame(200, $allowed->getStatusCode());
        $this->assertSame('ppv', $data['access_type']);
        $this->assertSame($rentalExpiry->timestamp, Carbon::parse($data['expires_at'])->timestamp);
    }

    #[TestWith([5])]
    #[TestWith([30])]
    #[TestWith([90])]
    public function test_premium_download_expires_at_the_earlier_of_plan_end_or_thirty_days(int $remainingDays): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'Asia/Kolkata'));
        DB::table('movie')->insert([
            'id' => 'premium-expiry',
            'title' => 'Premium expiry movie',
            'isPremium' => true,
            'isPayPerView' => false,
        ]);
        $planExpiry = now()->addDays($remainingDays)->startOfSecond();
        Subscription::query()->update(['end_at' => $planExpiry]);

        $movies = Mockery::mock(MovieController::class);
        $movies->shouldReceive('getLink')->once()->andReturn(response()->json([
            'status' => 'success',
            'links' => ['hls_url' => 'https://cdn.example.test/premium/master.m3u8'],
        ]));
        $controller = new OfflineController(Mockery::mock(HlsFolderController::class), $movies);
        $response = $controller->requestOffline($this->iosRequest('premium-expiry', 'movie'));
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('subscription', $data['access_type']);
        $expectedExpiry = now()->addDays(min($remainingDays, 30));
        $this->assertSame($expectedExpiry->timestamp, Carbon::parse($data['expires_at'])->timestamp);
    }

    public function test_daily_download_limit_allows_three_unique_items_and_does_not_charge_retries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-29 18:00:00', 'Asia/Kolkata'));

        foreach (['movie-2', 'movie-3', 'movie-4'] as $id) {
            DB::table('movie')->insert([
                'id' => $id,
                'title' => $id,
                'isPremium' => false,
                'isPayPerView' => false,
            ]);
        }

        $movies = Mockery::mock(MovieController::class);
        $movies->shouldReceive('getLink')->times(5)->andReturn(response()->json([
            'status' => 'success',
            'links' => ['hls_url' => 'https://cdn.example.test/movie/master.m3u8'],
        ]));
        $controller = new OfflineController(Mockery::mock(HlsFolderController::class), $movies);

        $first = $controller->requestOffline($this->iosRequest('movie-1', 'movie', false));
        $retry = $controller->requestOffline($this->iosRequest('movie-1', 'movie', false));
        $second = $controller->requestOffline($this->iosRequest('movie-2', 'movie', false));
        $third = $controller->requestOffline($this->iosRequest('movie-3', 'movie', false));
        $blocked = $controller->requestOffline($this->iosRequest('movie-4', 'movie', false));

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(2, $first->getData(true)['download_quota']['remaining']);
        $this->assertSame(200, $retry->getStatusCode());
        $this->assertSame(2, $retry->getData(true)['download_quota']['remaining']);
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame(200, $third->getStatusCode());
        $this->assertSame(0, $third->getData(true)['download_quota']['remaining']);
        $this->assertSame(429, $blocked->getStatusCode());
        $this->assertSame('OFFLINE_DAILY_LIMIT_REACHED', $blocked->getData(true)['code']);
        $this->assertSame(3, DB::table('offline_download_grants')->count());
        $this->assertSame(3, DB::table('offline_download_daily_quotas')->value('downloads_count'));
    }

    public function test_daily_download_limit_resets_at_local_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-29 23:55:00', 'Asia/Kolkata'));

        foreach (['movie-2', 'movie-3', 'movie-4'] as $id) {
            DB::table('movie')->insert([
                'id' => $id,
                'title' => $id,
                'isPremium' => false,
                'isPayPerView' => false,
            ]);
        }

        $movies = Mockery::mock(MovieController::class);
        $movies->shouldReceive('getLink')->times(4)->andReturn(response()->json([
            'status' => 'success',
            'links' => ['hls_url' => 'https://cdn.example.test/movie/master.m3u8'],
        ]));
        $controller = new OfflineController(Mockery::mock(HlsFolderController::class), $movies);

        foreach (['movie-1', 'movie-2', 'movie-3'] as $id) {
            $this->assertSame(200, $controller->requestOffline($this->iosRequest($id, 'movie', false))->getStatusCode());
        }

        Carbon::setTestNow(Carbon::parse('2026-09-30 00:01:00', 'Asia/Kolkata'));
        $nextDay = $controller->requestOffline($this->iosRequest('movie-4', 'movie', false));

        $this->assertSame(200, $nextDay->getStatusCode());
        $this->assertSame(2, $nextDay->getData(true)['download_quota']['remaining']);
        $this->assertSame(2, DB::table('offline_download_daily_quotas')->count());
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

    private function encryptOfflineSource(string $url): string
    {
        $key = hash(
            'sha256',
            'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a',
            true,
        );
        $iv = random_bytes(16);
        $cipherText = openssl_encrypt($url, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        $this->assertNotFalse($cipherText);

        return base64_encode($iv.$cipherText);
    }
}
