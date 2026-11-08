<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V4\AnalyticsReportController;
use App\Http\Controllers\Api\V4\AnalyticsIngestionController;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnalyticsDashboardReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.analytics' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('analytics');
        (require database_path('migrations/2026_10_06_000001_create_analytics_tables.php'))->up();
        // Production reports use MySQL. Supply the two MySQL functions needed
        // by the overview query while testing aggregation against isolated SQLite.
        $pdo = DB::connection('analytics')->getPdo();
        $pdo->sqliteCreateFunction('JSON_UNQUOTE', fn ($value) => $value);
        $pdo->sqliteCreateFunction('CONVERT_TZ', fn ($value, $from, $to) =>
            CarbonImmutable::parse($value, $from)->setTimezone($to)->format('Y-m-d H:i:s'));
        $pdo->sqliteCreateFunction('JSON_CONTAINS', fn ($value, $needle) =>
            in_array((int) $needle, json_decode((string) $value, true) ?: [], true) ? 1 : 0);
        foreach (['movie', 'episodes'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->string('id')->primary();
                $blueprint->unsignedBigInteger('num')->nullable()->unique();
                $blueprint->string('title');
                if ($table === 'episodes') {
                    $blueprint->string('season_id')->nullable();
                }
            });
        }
        Schema::create('seasons', function (Blueprint $blueprint) {
            $blueprint->string('id')->primary();
            $blueprint->unsignedBigInteger('num')->nullable()->unique();
            $blueprint->unsignedBigInteger('movie_id');
            $blueprint->string('title');
        });
        DB::table('movie')->insert([
            ['id' => 'short', 'num' => 10, 'title' => 'Short starts'],
            ['id' => 'watched', 'num' => 20, 'title' => 'Watched film'],
        ]);
        DB::table('seasons')->insert([
            'id' => 'season-1', 'num' => 100, 'movie_id' => 20, 'title' => 'Season 1',
        ]);
        DB::table('episodes')->insert([
            'id' => 'episode-1', 'num' => 1000, 'title' => 'First episode',
            'season_id' => 'season-1',
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('analytics');
        parent::tearDown();
    }

    public function test_overview_ranks_valid_views_and_keeps_session_completion_denominator(): void
    {
        $this->seedPlayback('a', 'short', 9000);
        $this->seedPlayback('b', 'short', 2000);
        $this->seedPlayback('c', 'watched', 10000, ['completed' => true]);
        $this->seedPlayback('d', 'watched', 1000);
        $data = $this->report('overview');

        $this->assertSame(4, $data['playback_starts']);
        $this->assertSame(1, $data['valid_views']);
        $this->assertSame('watched', $data['top_content'][0]['content_id']);
        $this->assertSame('Watched film', $data['top_content'][0]['title']);
        $this->assertSame(1, $data['top_content'][0]['views']);
        $this->assertEquals(50, $data['top_content'][0]['completion_rate']);
        $this->assertEquals(0.09, $data['average_watch_minutes']);
    }

    public function test_content_resolves_movie_and_episode_titles_with_id_fallback(): void
    {
        $this->seedPlayback('a', 'watched', 20000);
        $this->seedPlayback('b', 'episode-1', 15000, ['content_type' => 'episode']);
        $this->seedPlayback('c', 'deleted', 10000);
        $rows = $this->report('content')['data'];
        $this->assertSame(['Watched film', 'First episode', 'deleted'], array_column($rows, 'title'));
        $episode = collect($rows)->firstWhere('content_type', 'episode');
        $this->assertSame('Watched film', $episode['parent_title']);
        $this->assertSame('Watched film — First episode', $episode['display_title']);
    }

    public function test_sessions_preserve_filters_and_paginate_user_history(): void
    {
        $this->seedPlayback('a', 'watched', 20000);
        $this->seedPlayback('b', 'watched', 15000);
        $this->seedPlayback('c', 'watched', 10000, ['user_id' => 'another-user']);
        $this->seedPlayback('d', 'short', 10000);
        $data = $this->report('sessions', [
            'user_id' => 'user-1', 'content_id' => 'watched', 'platform' => 'android',
            'app_version' => '2.0', 'content_type' => 'movie', 'per_page' => 1,
        ]);
        $this->assertSame(2, $data['total']);
        $this->assertSame(2, $data['last_page']);
        $this->assertCount(1, $data['data']);
        $this->assertSame('user-1', $data['data'][0]['user_id']);
        $this->assertSame('watched', $data['data'][0]['content_id']);
        $this->assertSame('Watched film', $data['data'][0]['display_title']);
    }

    public function test_sessions_and_insights_show_episode_and_parent_movie_titles(): void
    {
        $this->seedPlayback('episode-session', 'episode-1', 20000, [
            'content_type' => 'episode',
            'metrics_json' => json_encode(['content' => ['episode_id' => 'episode-1']]),
        ]);

        $session = $this->report('sessions')['data'][0];
        $this->assertSame('First episode', $session['title']);
        $this->assertSame('Watched film', $session['parent_title']);
        $this->assertSame('Watched film — First episode', $session['display_title']);

        $insights = $this->report('insights', ['dimension' => 'episode_id']);
        $this->assertSame(
            'Watched film — First episode',
            $insights['groups']['data'][0]['dimension_label']
        );
    }

    public function test_numeric_catalog_ids_from_older_clients_are_resolved(): void
    {
        $this->seedPlayback('numeric-movie', '20', 20000);
        $this->seedPlayback('numeric-episode', '1000', 20000, [
            'content_type' => 'episode',
        ]);

        $rows = collect($this->report('content')['data'])->keyBy('content_id');
        $this->assertSame('Watched film', $rows['20']['title']);
        $this->assertSame('First episode', $rows['1000']['title']);
        $this->assertSame('Watched film', $rows['1000']['parent_title']);
    }

    public function test_transferred_bytes_are_available_in_analytics_reports(): void
    {
        $this->seedPlayback('bandwidth-a', 'watched', 20000, [
            'metrics_json' => json_encode(['quality' => ['bytes_transferred' => 10_000_000]]),
        ]);
        $this->seedPlayback('bandwidth-b', 'watched', 15000, [
            'metrics_json' => json_encode(['quality' => ['bytes_transferred' => 5_000_000]]),
        ]);

        $overview = $this->report('overview');
        $this->assertSame(15_000_000, $overview['engagement']['data_transferred_bytes']);
        $this->assertSame(15_000_000, $overview['top_content'][0]['data_transferred_bytes']);

        $content = $this->report('content')['data'][0];
        $this->assertSame(15_000_000, $content['data_transferred_bytes']);

        $session = $this->report('sessions')['data'][0];
        $this->assertSame(5_000_000, $session['data_transferred_bytes']);

        $quality = $this->report('quality')[0];
        $this->assertSame(15_000_000, $quality['data_transferred_bytes']);

        $insights = $this->report('insights', ['dimension' => 'platform']);
        $this->assertSame(15_000_000, $insights['summary']['bytes_transferred']);
    }

    public function test_empty_overview_does_not_invent_activity(): void
    {
        $data = $this->report('overview');
        $this->assertSame(0, $data['playback_starts']);
        $this->assertSame([], $data['top_content']);
        $this->assertSame([], $data['watch_trend']);
    }

    public function test_insights_aggregate_sdk_metrics_and_group_device_attributes(): void
    {
        $this->seedPlayback('a', 'watched', 30000, [
            'metrics_json' => json_encode([
                'content' => ['is_downloaded' => true, 'autoplay' => false],
                'timing' => ['watched_ms' => 30000, 'unique_watched_ms' => 24000,
                    'replayed_ms' => 6000, 'startup_ms' => 500],
                'interaction' => ['seek_count' => 3, 'pip_count' => 1],
                'buffering' => ['count' => 2, 'total_ms' => 1500, 'longest_ms' => 1000],
                'quality' => ['change_count' => 2, 'video_codec' => 'h264',
                    'dropped_frames' => 4],
                'tracks' => ['subtitle_enabled' => true, 'audio_language' => 'en',
                    'playback_speed' => 1.25],
                'result' => ['milestones' => [25, 50]],
                'context' => ['network_type' => 'wifi', 'device_model' => 'Phone A'],
            ]),
        ]);
        $this->seedPlayback('b', 'short', 2000, [
            'metrics_json' => json_encode([
                'content' => ['is_downloaded' => false, 'autoplay' => true],
                'result' => ['milestones' => []],
                'context' => ['network_type' => 'cellular'],
            ]),
        ]);

        $data = $this->report('insights', ['dimension' => 'network_type']);
        $this->assertSame(2, $data['summary']['sessions']);
        $this->assertSame(1, $data['summary']['valid_views']);
        $this->assertSame(1, $data['summary']['downloaded_sessions']);
        $this->assertSame(1, $data['summary']['autoplay_sessions']);
        $this->assertSame(1, $data['summary']['subtitle_sessions']);
        $this->assertSame(3, $data['summary']['seek_count']);
        $this->assertSame(2, $data['summary']['quality_changes']);
        $this->assertSame(1, $data['summary']['milestone_50']);
        $this->assertSame(0, $data['summary']['milestone_90']);
        $this->assertSame('cellular', $data['groups']['data'][0]['dimension_value']);
        $this->assertSame('wifi', $data['groups']['data'][1]['dimension_value']);

        $filtered = $this->report('insights', [
            'dimension' => 'device_model', 'content_type' => 'movie', 'user_id' => 'user-1',
        ]);
        $this->assertSame('Phone A', $filtered['groups']['data'][1]['dimension_value']);
    }

    public function test_insights_reject_unknown_dimensions(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->report('insights', ['dimension' => 'metrics_json']);
    }

    public function test_short_sdk_playback_accepts_empty_milestones(): void
    {
        $payload = json_decode(
            file_get_contents(base_path('Library/contract/playback-final.example.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $payload['result']['milestones'] = [];
        $payload['result']['completion_percent'] = 10;
        $controller = app(AnalyticsIngestionController::class);
        $rules = (new \ReflectionMethod($controller, 'playbackRules'))->invoke($controller);

        $this->assertTrue(validator($payload, $rules)->passes());
        $nestedRules = (new \ReflectionMethod($controller, 'playbackRules'))
            ->invoke($controller, 'sessions.*.summary.');
        $this->assertTrue(validator([
            'sessions' => [['summary' => $payload]],
        ], $nestedRules)->passes());
    }

    public function test_insights_route_requires_admin_token(): void
    {
        $route = Route::getRoutes()->match(Request::create(
            '/api/v4/analytic/reports/insights',
            'GET'
        ));

        $this->assertContains('auth.token', $route->gatherMiddleware());
        $this->assertContains('admin.token', $route->gatherMiddleware());
    }

    private function report(string $method, array $filters = []): array
    {
        $request = Request::create('/reports/'.$method, 'GET', $filters + [
            'from' => '2026-10-06', 'to' => '2026-10-06', 'timezone' => 'Asia/Kolkata',
        ]);
        return app(AnalyticsReportController::class)->{$method}($request)->getData(true)['data'];
    }

    private function seedPlayback(string $id, string $content, int $watched, array $extra = []): void
    {
        DB::connection('analytics')->table('playback_sessions')->insert($extra + [
            'session_id' => $id, 'user_id' => 'user-1', 'device_id' => 'device-1',
            'content_id' => $content, 'content_type' => 'movie', 'state' => 'final',
            'started_at' => '2026-10-06 06:00:00', 'ended_at' => '2026-10-06 06:01:00',
            'watched_ms' => $watched, 'platform' => 'android', 'app_version' => '2.0',
            'metrics_json' => '{}',
        ]);
    }
}
