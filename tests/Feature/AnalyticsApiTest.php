<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V4\AnalyticsIngestionController;
use App\Http\Controllers\Api\V4\AnalyticsReportController;
use App\Support\Analytics\AnalyticsPresenceStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AnalyticsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.analytics' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('analytics');
        (require database_path('migrations/2026_10_06_000001_create_analytics_tables.php'))->up();
        app()->instance(AnalyticsIngestionController::class, new class extends AnalyticsIngestionController
        {
            protected function analyticsEnabled(): bool
            {
                return true;
            }
        });
        $pdo = DB::connection('analytics')->getPdo();
        $pdo->sqliteCreateFunction('JSON_UNQUOTE', fn ($value) => $value);
        $pdo->sqliteCreateFunction('CONVERT_TZ', fn ($value, $from, $to) => CarbonImmutable::parse($value, $from)->setTimezone($to)->format('Y-m-d H:i:s'));
        $pdo->sqliteCreateFunction('JSON_CONTAINS', fn ($value, $needle) => in_array((int) $needle, json_decode((string) $value, true) ?: [], true) ? 1 : 0);
    }

    protected function tearDown(): void
    {
        DB::purge('analytics');
        parent::tearDown();
    }

    public function test_playback_upsert_keeps_the_highest_revision(): void
    {
        $controller = app(AnalyticsIngestionController::class);
        $sessionId = '019b1234-7e58-7000-a123-456789abcdef';

        $newer = $controller->upsertPlayback(
            $this->analyticsRequest($this->playbackPayload(2)),
            $sessionId
        );
        $older = $controller->upsertPlayback(
            $this->analyticsRequest($this->playbackPayload(1)),
            $sessionId
        );

        $this->assertSame(200, $newer->getStatusCode());
        $this->assertTrue($newer->getData(true)['data']['stored']);
        $this->assertFalse($older->getData(true)['data']['stored']);
        $this->assertSame(1, DB::connection('analytics')->table('playback_sessions')->count());
        $this->assertSame(
            2,
            DB::connection('analytics')->table('playback_sessions')->value('revision')
        );
    }

    public function test_playback_session_cannot_be_revised_by_another_device(): void
    {
        $controller = app(AnalyticsIngestionController::class);
        $sessionId = '019b1234-7e58-7000-a123-456789abcdef';
        $controller->upsertPlayback($this->analyticsRequest($this->playbackPayload(1)), $sessionId);

        $request = $this->analyticsRequest($this->playbackPayload(2));
        $request->merge(['auth_device_id' => 'device-2']);
        $request->headers->set('Device-Token', 'device-2');
        $response = $controller->upsertPlayback($request, $sessionId);

        $this->assertFalse($response->getData(true)['data']['stored']);
        $this->assertSame(1, DB::connection('analytics')->table('playback_sessions')->value('revision'));
        $this->assertSame('device-1', DB::connection('analytics')->table('playback_sessions')->value('device_id'));
    }

    public function test_event_batch_is_idempotent(): void
    {
        $controller = app(AnalyticsIngestionController::class);
        $event = [
            'event_id' => '019b1234-a222-7000-a123-456789abcdef',
            'name' => 'content_opened',
            'occurred_at' => now('UTC')->toIso8601String(),
            'app_session_id' => '019b1234-a000-7000-a123-456789abcdef',
            'properties' => ['content_id' => 'movie-123'],
        ];
        $payload = [
            'schema_version' => 1,
            'events' => [$event],
            'context' => ['platform' => 'android', 'app_version' => '2.4.0'],
        ];

        $first = $controller->batchEvents($this->analyticsRequest($payload));
        $second = $controller->batchEvents($this->analyticsRequest($payload));

        $this->assertSame([$event['event_id']], $first->getData(true)['data']['accepted']);
        $this->assertSame([$event['event_id']], $second->getData(true)['data']['ignored']);
        $this->assertSame(1, DB::connection('analytics')->table('analytics_events')->count());
    }

    public function test_batch_playback_and_error_ingestion_are_idempotent(): void
    {
        $controller = app(AnalyticsIngestionController::class);
        $sessionId = '019b1234-7e58-7000-a123-456789abcdef';
        $batch = $controller->batchPlayback($this->analyticsRequest([
            'schema_version' => 1,
            'sessions' => [[
                'session_id' => $sessionId,
                'summary' => $this->playbackPayload(),
            ]],
        ]));
        $errorPayload = [
            'schema_version' => 1,
            'event_id' => '019b1234-9701-7000-b123-456789abcdef',
            'occurred_at' => now('UTC')->toIso8601String(),
            'position_ms' => 1_000,
            'category' => 'segment',
            'stage' => 'during_playback',
            'code' => 'HTTP_404',
            'http_status' => 404,
            'is_fatal' => false,
            'is_retryable' => true,
            'retry_count' => 1,
            'network_type' => 'wifi',
            'sanitized_message' => 'Failed https://example.test/secret Bearer private-token',
        ];
        $firstError = $controller->storePlaybackError(
            $this->analyticsRequest($errorPayload),
            $sessionId
        );
        $secondError = $controller->storePlaybackError(
            $this->analyticsRequest($errorPayload),
            $sessionId
        );

        $this->assertCount(1, $batch->getData(true)['data']['accepted']);
        $this->assertTrue($firstError->getData(true)['data']['stored']);
        $this->assertFalse($secondError->getData(true)['data']['stored']);
        $this->assertSame(
            'Failed [url] Bearer [redacted]',
            DB::connection('analytics')->table('playback_errors')->value('sanitized_message')
        );
    }

    public function test_config_returns_the_sdk_contract(): void
    {
        $response = app(AnalyticsIngestionController::class)
            ->config($this->analyticsRequest([]));

        $this->assertSame(1, $response->getData(true)['data']['schema_version']);
        $this->assertSame(20, $response->getData(true)['data']['max_batch_size']);
        $this->assertFalse($response->getData(true)['data']['checkpoint_upload_enabled']);
        $this->assertTrue($response->getData(true)['data']['presence_heartbeat_enabled']);
        $this->assertSame(60, $response->getData(true)['data']['presence_heartbeat_interval_seconds']);
        $this->assertSame(150, $response->getData(true)['data']['presence_ttl_seconds']);
    }

    public function test_presence_heartbeat_uses_ephemeral_store_and_reports_online_users(): void
    {
        $store = new class extends AnalyticsPresenceStore
        {
            public array $lastHeartbeat = [];

            public function heartbeat(array $presence): array
            {
                $this->lastHeartbeat = $presence;

                return $presence + ['last_seen_at' => '2026-10-07T12:00:00+00:00'];
            }

            public function snapshot(array $filters = []): array
            {
                return [
                    'available' => true,
                    'online_users' => 1,
                    'online_devices' => 1,
                    'heartbeat_interval_seconds' => 60,
                    'presence_ttl_seconds' => 150,
                    'as_of' => '2026-10-07T12:00:00+00:00',
                    'platforms' => [['platform' => 'android', 'users' => 1, 'devices' => 1]],
                    'devices' => [$this->lastHeartbeat],
                    'devices_truncated' => false,
                ];
            }
        };
        app()->instance(AnalyticsPresenceStore::class, $store);

        $heartbeat = app(AnalyticsIngestionController::class)->presence($this->analyticsRequest([
            'state' => 'foreground',
            'context' => [
                'platform' => 'android',
                'app_version' => '3.0.0',
                'build_number' => '300',
                'device_model' => 'Pixel',
                'network_type' => 'wifi',
            ],
        ]));
        $report = app(AnalyticsReportController::class)->presence(Request::create(
            '/reports/presence',
            'GET',
            ['platform' => 'android']
        ));

        $this->assertTrue($heartbeat->getData(true)['data']['accepted']);
        $this->assertSame('user-1', $store->lastHeartbeat['user_id']);
        $this->assertSame('device-1', $store->lastHeartbeat['device_id']);
        $this->assertSame('3.0.0', $store->lastHeartbeat['app_version']);
        $this->assertSame(1, $report->getData(true)['data']['online_users']);
    }

    public function test_device_header_must_match_the_authenticated_device(): void
    {
        $request = $this->analyticsRequest($this->playbackPayload());
        $request->headers->set('Device-Token', 'other-device');

        $response = app(AnalyticsIngestionController::class)->upsertPlayback(
            $request,
            '019b1234-7e58-7000-a123-456789abcdef'
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('ANALYTICS_DEVICE_MISMATCH', $response->getData(true)['error']['code']);
        $this->assertSame(0, DB::connection('analytics')->table('playback_sessions')->count());
    }

    public function test_overview_reports_stored_playback_and_events(): void
    {
        $ingestion = app(AnalyticsIngestionController::class);
        $ingestion->upsertPlayback(
            $this->analyticsRequest($this->playbackPayload()),
            '019b1234-7e58-7000-a123-456789abcdef'
        );
        $ingestion->batchEvents($this->analyticsRequest([
            'schema_version' => 1,
            'events' => [[
                'event_id' => '019b1234-a222-7000-a123-456789abcdef',
                'name' => 'app_opened',
                'occurred_at' => now('UTC')->toIso8601String(),
                'app_session_id' => '019b1234-a000-7000-a123-456789abcdef',
                'properties' => [],
            ]],
            'context' => ['platform' => 'android', 'app_version' => '2.4.0'],
        ]));

        $request = Request::create('/reports/overview', 'GET', [
            'from' => now('UTC')->toDateString(),
            'to' => now('UTC')->toDateString(),
            'timezone' => 'UTC',
        ]);
        $response = app(AnalyticsReportController::class)->overview($request);
        $data = $response->getData(true)['data'];

        $this->assertSame(1, $data['playback_starts']);
        $this->assertSame(1, $data['valid_views']);
        $this->assertSame(1, $data['unique_viewers']);
        $this->assertSame(1, $data['app_sessions']);
        $this->assertSame(157_286_400, $data['engagement']['data_transferred_bytes']);
        $this->assertSame('app_opened', $data['product_events'][0]['name']);
    }

    public function test_tv_sdk_data_is_available_to_admin_analytics_reports(): void
    {
        $ingestion = app(AnalyticsIngestionController::class);
        $payload = $this->playbackPayload();
        $payload['context']['platform'] = 'tv';
        $payload['context']['os_version'] = 'Tizen 8.0';
        $payload['context']['device_model'] = 'Samsung Smart TV';
        $playbackRequest = $this->analyticsRequest($payload);
        $playbackRequest->headers->set('X-Platform', 'tv');

        $response = $ingestion->upsertPlayback(
            $playbackRequest,
            '019b1234-7e58-7000-b123-456789abcdef'
        );

        $eventRequest = $this->analyticsRequest([
            'schema_version' => 1,
            'events' => [[
                'event_id' => '019b1234-a222-7000-b123-456789abcdef',
                'name' => 'screen_viewed',
                'occurred_at' => now('UTC')->toIso8601String(),
                'app_session_id' => '019b1234-a000-7000-b123-456789abcdef',
                'properties' => ['screen_name' => 'home'],
            ]],
            'context' => ['platform' => 'tv', 'app_version' => '1.0.0'],
        ]);
        $eventRequest->headers->set('X-Platform', 'tv');
        $ingestion->batchEvents($eventRequest);

        $report = app(AnalyticsReportController::class)->overview(Request::create(
            '/reports/overview',
            'GET',
            [
                'from' => now('UTC')->toDateString(),
                'to' => now('UTC')->toDateString(),
                'timezone' => 'UTC',
                'platform' => 'tv',
            ]
        ))->getData(true)['data'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('tv', DB::connection('analytics')->table('playback_sessions')->value('platform'));
        $this->assertSame('1.4.0', DB::connection('analytics')->table('playback_sessions')->value('sdk_version'));
        $this->assertSame('tv', DB::connection('analytics')->table('analytics_events')->value('platform'));
        $this->assertSame(1, $report['playback_starts']);
        $this->assertSame('tv', $report['platforms'][0]['platform']);
        $this->assertSame('screen_viewed', $report['product_events'][0]['name']);
    }

    public function test_analytics_routes_have_customer_and_admin_boundaries(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v4/analytic/'));

        $this->assertCount(17, $routes);
        foreach ($routes as $route) {
            $this->assertContains('auth.token', $route->gatherMiddleware(), $route->uri());
            if (str_contains($route->uri(), '/reports/')) {
                $this->assertContains('admin.token', $route->gatherMiddleware(), $route->uri());
            }
        }
    }

    private function analyticsRequest(array $payload): Request
    {
        $request = Request::create(
            '/api/v4/analytic/test',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
        $request->headers->set('Device-Token', 'device-1');
        $request->headers->set('X-Platform', 'android');
        $request->headers->set('X-Analytics-SDK-Version', '1.4.0');
        $request->merge([
            'auth_user_id' => 'user-1',
            'auth_device_id' => 'device-1',
        ]);

        return $request;
    }

    private function playbackPayload(int $revision = 1): array
    {
        $payload = json_decode(file_get_contents(base_path('Library/contract/playback-final.example.json')), true, 512, JSON_THROW_ON_ERROR);
        $payload['revision'] = $revision;
        $payload['started_at'] = now('UTC')->subMinutes(30)->toIso8601String();
        $payload['ended_at'] = now('UTC')->toIso8601String();
        $payload['end_reason'] = 'user_closed';
        $payload['context']['platform'] = 'android';

        return $payload;
    }
}
