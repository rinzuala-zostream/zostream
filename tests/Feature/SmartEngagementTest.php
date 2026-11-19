<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V4\EngagementPreferenceController;
use App\Http\Controllers\FCMNotificationController;
use App\Jobs\SendSmartEngagement;
use App\Services\Engagement\EngagementRules;
use App\Services\Engagement\EngagementTiming;
use App\Services\Engagement\EngagementTracker;
use App\Services\Engagement\ExistingPushActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SmartEngagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'engagement_test', 'database.connections.engagement_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ], 'engagement.enabled' => true, 'engagement.tracking_enabled' => true]);
        DB::purge('engagement_test');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-19 12:30:00', 'UTC')); // 18:00 IST
        \Illuminate\Support\Carbon::setTestNow(CarbonImmutable::now('UTC'));
        (require database_path('migrations/2026_11_19_000001_create_smart_engagement_tables.php'))->up();
        Schema::create('user', function (Blueprint $table) {
            $table->string('uid')->primary();
            $table->text('token')->nullable();
            $table->boolean('isACActive')->default(true);
        });
        Schema::create('movie', function (Blueprint $table) {
            $table->increments('num');
            $table->string('id')->unique();
            $table->string('title');
            $table->string('genre')->default('Drama');
            $table->string('poster')->default('');
            $table->string('status')->default('Published');
            foreach (['isEnable', 'isChildMode', 'isMizo'] as $column) {
                $table->boolean($column)->default(true);
            }
            foreach (['isPremium', 'isAgeRestricted', 'isPayPerView'] as $column) {
                $table->boolean($column)->default(false);
            }
            $table->integer('views')->default(1);
        });
        Schema::create('wist_list', function (Blueprint $table) {
            $table->id();
            $table->string('uid');
            $table->string('movie_id');
            $table->timestamps();
        });
        Schema::create('watch_position', function (Blueprint $table) {
            $table->increments('num');
            $table->string('user_id');
            $table->string('movie_id');
            $table->string('movie_type')->nullable();
            $table->integer('position');
            $table->integer('duration')->nullable();
        });
        Schema::create('n_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->boolean('is_active')->default(true);
            $table->dateTime('start_at');
            $table->dateTime('end_at');
        });
        Schema::create('n_devices', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
        });
        Schema::create('n_active_streams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->string('status');
            $table->dateTime('last_ping');
        });
        DB::table('user')->insert(['uid' => 'u1', 'token' => 'test-push-token']);
        DB::table('movie')->insert([['id' => 'm1', 'title' => 'First'], ['id' => 'm2', 'title' => 'Second']]);
        $this->activity('2026-11-18T10:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        DB::disconnect('engagement_test');
        parent::tearDown();
    }

    public function test_quiet_hours_and_preferred_hour_stay_within_ist_window(): void
    {
        $timing = app(EngagementTiming::class);
        foreach (['2026-11-19T15:30:00Z', '2026-11-20T03:29:59Z'] as $at) {
            $this->assertTrue($timing->quiet(CarbonImmutable::parse($at)));
        }
        $this->assertFalse($timing->quiet(CarbonImmutable::parse('2026-11-20T03:30:00Z')));
        $this->assertSame('20:00', $timing->nextSlot(CarbonImmutable::now('UTC'), '{"23":4}')->setTimezone('Asia/Kolkata')->format('H:i'));
        $slot = $timing->nextSlot(CarbonImmutable::parse('2026-11-19T15:30:00Z'));
        $this->assertSame('2026-11-20 18:00', $slot->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'));
    }

    public function test_priority_cooldown_and_one_step_dedupe(): void
    {
        $this->progress('m1', '2026-11-17T12:00:00Z');
        DB::table('wist_list')->insert(['uid' => 'u1', 'movie_id' => 'm2', 'created_at' => '2026-11-10 10:00:00']);
        $this->assertSame('continue_watching', $this->candidate()['rule']);
        DB::table('engagement_users')->where('user_id', 'u1')->update(['last_sent_at' => '2026-11-18 12:00:00']);
        $this->assertNull($this->candidate());
    }

    public function test_mature_watchlist_reminder_suppresses_previously_watched_content(): void
    {
        DB::table('wist_list')->insert(['uid' => 'u1', 'movie_id' => 'm1', 'created_at' => '2026-11-10 10:00:00']);
        $this->assertSame('watchlist', $this->candidate()['rule']);
        DB::table('watch_position')->insert(['user_id' => 'u1', 'movie_id' => 'm1', 'position' => 1]);
        $this->assertNull($this->candidate());
    }

    public function test_active_subscriber_with_no_playback_and_current_comeback_milestone(): void
    {
        DB::table('n_subscriptions')->insert(['user_id' => 'u1', 'start_at' => '2026-11-10 00:00:00', 'end_at' => '2026-12-10 23:59:59']);
        $this->assertSame('unused_subscription', $this->candidate()['rule']);
        DB::table('n_subscriptions')->delete();
        DB::table('engagement_users')->update(['last_active_at' => '2026-11-03 12:00:00']);
        $this->assertSame('comeback', $this->candidate()['rule']);
        $this->assertSame('14', $this->candidate()['source_key']);
    }

    public function test_search_uses_selected_content_without_storing_query_text(): void
    {
        app(EngagementTracker::class)->events('u1', [[
            'name' => 'search_result_selected', 'occurred_at' => '2026-11-18T12:00:00Z',
            'properties' => ['content_id' => 'm1', 'query' => 'private search'],
        ]]);
        $candidate = $this->candidate();
        $this->assertSame('search_interest', $candidate['rule']);
        $this->assertSame('m2', $candidate['content_id']);
        $this->assertStringNotContainsString('private search', json_encode((array) $this->state()));
    }

    public function test_completed_movie_selects_a_different_unseen_movie(): void
    {
        $this->progress('m1', '2026-11-17T12:00:00Z', 100);
        $this->assertSame('post_watch', $this->candidate()['rule']);
        $this->assertSame('m2', $this->candidate()['content_id']);
    }

    public function test_out_of_order_or_future_playback_cannot_overwrite_current_state(): void
    {
        $this->progress('m1', '2026-11-18T12:00:00Z', 100);
        $this->progress('m1', '2026-11-17T12:00:00Z', 20);
        $this->progress('m1', '2026-11-25T12:00:00Z', 20);
        $content = DB::table('engagement_content')->first();
        $this->assertSame(100000, $content->position_ms);
        $this->assertNotNull($content->completed_at);
        $this->assertSame('2026-11-18 12:00:00', $this->state()->last_watch_at);
    }

    public function test_dry_run_has_no_deliveries_and_dispatch_is_bounded_and_deduplicated(): void
    {
        Queue::fake();
        $this->progress('m1', '2026-11-17T12:00:00Z');
        $this->artisan('engagement:queue', ['--dry-run' => true, '--limit' => 1])->assertSuccessful();
        $this->assertSame(0, DB::table('engagement_deliveries')->count());
        Queue::assertNothingPushed();
        $this->artisan('engagement:queue', ['--limit' => 1])->assertSuccessful();
        $this->artisan('engagement:queue', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(1, DB::table('engagement_deliveries')->count());
        Queue::assertPushed(SendSmartEngagement::class, 1);
    }

    public function test_worker_rechecks_completion_after_enqueue(): void
    {
        $id = $this->delivery();
        $this->progress('m1', '2026-11-19T12:00:00Z', 100);
        $push = Mockery::mock(FCMNotificationController::class);
        $push->shouldNotReceive('sendToToken');
        $this->send($id, $push);
        $this->assertSame('cancelled', DB::table('engagement_deliveries')->value('status'));
    }

    public function test_worker_postpones_for_existing_push_and_for_late_queue_execution(): void
    {
        $id = $this->delivery();
        $push = Mockery::mock(FCMNotificationController::class);
        $push->shouldNotReceive('sendToToken');
        app(ExistingPushActivity::class)->record();
        $this->send($id, $push);
        $this->assertSame('existing_notification', DB::table('engagement_deliveries')->value('reason'));
        $this->assertSame('pending', DB::table('engagement_deliveries')->value('status'));
        Cache::forget('engagement:existing:broadcast');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20T16:00:00Z'));
        $this->send($id, $push);
        $this->assertSame('quiet_hours', DB::table('engagement_deliveries')->value('reason'));
    }

    public function test_send_is_once_and_open_and_play_are_attributed_to_owner(): void
    {
        $id = $this->delivery();
        $push = Mockery::mock(FCMNotificationController::class);
        $push->shouldReceive('sendToToken')->once()->with('test-push-token', 'Zo Stream', Mockery::type('string'), '', 'm1',
            Mockery::on(fn ($data) => $data['notification_id'] === (string) $id && $data['content_id'] === 'm1'))->andReturn(['success' => true]);
        $this->send($id, $push);
        $this->send($id, $push);
        $this->assertSame(1, $this->state()->unopened_count);
        $event = ['name' => 'notification_opened', 'occurred_at' => '2026-11-19T12:31:00Z', 'properties' => ['notification_id' => (string) $id]];
        app(EngagementTracker::class)->events('another-user', [$event]);
        $this->assertNull(DB::table('engagement_deliveries')->value('opened_at'));
        app(EngagementTracker::class)->events('u1', [$event]);
        $this->progress('m1', '2026-11-19T12:32:00Z', 60);
        $delivery = DB::table('engagement_deliveries')->first();
        $this->assertNotNull($delivery->opened_at);
        $this->assertNotNull($delivery->played_at);
        $this->assertSame(0, $this->state()->unopened_count);
    }

    public function test_optout_cancels_pending_only_and_never_changes_other_users(): void
    {
        $id = $this->delivery();
        $request = new Request(['auth_user_id' => 'u1', 'user_id' => 'another-user', 'enabled' => false]);
        app(EngagementPreferenceController::class)->update($request);
        $this->assertFalse((bool) $this->state()->enabled);
        $this->assertSame('cancelled', DB::table('engagement_deliveries')->where('id', $id)->value('status'));
        $this->assertNull($this->candidate());
    }

    public function test_content_filters_and_deleted_accounts_are_checked_at_send_time(): void
    {
        $id = $this->delivery();
        DB::table('movie')->where('id', 'm1')->update(['isAgeRestricted' => true]);
        $push = Mockery::mock(FCMNotificationController::class);
        $push->shouldNotReceive('sendToToken');
        $this->send($id, $push);
        $this->assertSame('cancelled', DB::table('engagement_deliveries')->value('status'));
    }

    public function test_five_ignored_pushes_stop_automation_and_disabled_tracking_is_a_noop(): void
    {
        $this->progress('m1', '2026-11-17T12:00:00Z');
        DB::table('engagement_users')->update(['unopened_count' => 5]);
        $this->assertNull($this->candidate());
        config(['engagement.tracking_enabled' => false]);
        app(EngagementTracker::class)->events('new-user', [['name' => 'app_opened', 'occurred_at' => '2026-11-19T12:00:00Z']]);
        $this->assertSame(1, DB::table('engagement_users')->count());
    }

    public function test_active_playback_defers_only_the_smart_reminder(): void
    {
        $id = $this->delivery();
        DB::table('n_devices')->insert(['id' => 1, 'user_id' => 'u1']);
        DB::table('n_active_streams')->insert(['device_id' => 1, 'status' => 'active', 'last_ping' => now()->toDateTimeString()]);
        $push = Mockery::mock(FCMNotificationController::class);
        $push->shouldNotReceive('sendToToken');
        $this->send($id, $push);
        $this->assertSame('currently_watching', DB::table('engagement_deliveries')->value('reason'));
    }

    public function test_failed_or_uncertain_fcm_acceptance_is_not_automatically_resent(): void
    {
        $id = $this->delivery();
        $push = Mockery::mock(FCMNotificationController::class);
        $push->shouldReceive('sendToToken')->once()->andReturn(['success' => false]);
        $this->send($id, $push);
        $this->send($id, $push);
        $this->assertSame('failed', DB::table('engagement_deliveries')->value('status'));
        $this->assertNull($this->state()->last_sent_at);
    }

    public function test_smart_payload_expires_before_quiet_hours_without_changing_existing_payload(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-19T15:25:00Z'));
        \Illuminate\Support\Carbon::setTestNow(CarbonImmutable::now('UTC'));
        $method = new \ReflectionMethod(FCMNotificationController::class, 'buildMessage');
        $smart = $method->invoke(new FCMNotificationController, 'token', null, 'Title', 'Body', null, 'm1', ['type' => 'smart_engagement'])->jsonSerialize();
        $this->assertSame('300s', $smart['android']['ttl']);
        $this->assertSame((string) CarbonImmutable::parse('2026-11-19T15:30:00Z')->timestamp, $smart['apns']['headers']['apns-expiration']);
        $existing = $method->invoke(new FCMNotificationController, 'token', null, 'Title', 'Body', null, 'm1', [])->jsonSerialize();
        $this->assertArrayNotHasKey('ttl', $existing['android']);
        $this->assertArrayNotHasKey('apns-expiration', $existing['apns']['headers']);
    }

    public function test_deleted_user_never_receives_a_pending_push(): void
    {
        $id = $this->delivery();
        DB::table('user')->delete();
        $push = Mockery::mock(FCMNotificationController::class);
        $push->shouldNotReceive('sendToToken');
        $this->send($id, $push);
        $this->assertSame('no_active_recipient', DB::table('engagement_deliveries')->value('reason'));
    }

    public function test_lookup_migration_does_not_duplicate_existing_prefix_indexes(): void
    {
        Schema::table('n_devices', fn (Blueprint $table) => $table->index('user_id', 'existing_user_lookup'));
        $migration = require database_path('migrations/2026_11_19_000002_index_smart_engagement_lookups.php');
        $migration->up();
        $migration->up();
        $names = array_column(Schema::getIndexes('n_devices'), 'name');
        $this->assertContains('existing_user_lookup', $names);
        $this->assertNotContains('engagement_device_user_idx', $names);
        $this->assertContains('engagement_subscription_user_idx', array_column(Schema::getIndexes('n_subscriptions'), 'name'));
        $migration->down();
        $this->assertContains('existing_user_lookup', array_column(Schema::getIndexes('n_devices'), 'name'));
    }

    public function test_new_viewing_suppresses_old_search_and_post_watch_suggestions(): void
    {
        $this->progress('m1', '2026-11-17T12:00:00Z', 100);
        $this->progress('m2', '2026-11-19T12:00:00Z', 20);
        $this->assertNull($this->candidate());
    }

    public function test_analytics_ingestion_enrolls_only_accepted_events(): void
    {
        config(['database.connections.analytics' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('analytics');
        try {
            (require database_path('migrations/2026_10_06_000001_create_analytics_tables.php'))->up();
            $controller = new class extends \App\Http\Controllers\Api\V4\AnalyticsIngestionController
            {
                protected function analyticsEnabled(): bool
                {
                    return true;
                }
            };
            $request = new Request([
                'auth_user_id' => 'u1', 'auth_device_id' => 'd1', 'schema_version' => 1,
                'context' => ['platform' => 'android', 'app_version' => '1.0'],
                'events' => [['event_id' => '019b1234-7e58-7000-a123-456789abcdef', 'name' => 'app_opened',
                    'occurred_at' => '2026-11-19T12:00:00Z', 'app_session_id' => '019b1234-7e58-7000-a123-456789abcdee', 'properties' => []]],
            ]);
            $request->headers->set('Device-Token', 'd1');
            $response = $controller->batchEvents($request);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('2026-11-19 12:00:00', $this->state()->last_active_at);
            DB::table('engagement_users')->update(['last_active_at' => '2026-11-18 12:00:00']);
            $controller->batchEvents($request);
            $this->assertSame('2026-11-18 12:00:00', $this->state()->last_active_at);
        } finally {
            DB::purge('analytics');
        }
    }

    public function test_body_auth_fields_cannot_enroll_legacy_progress_but_trusted_progress_can(): void
    {
        $request = new Request(['user_id' => 'u1', 'auth_user_id' => 'u1', 'movie_id' => 'm1', 'movie_type' => 'movie', 'position' => 40, 'duration' => 100]);
        $controller = app(\App\Http\Controllers\WatchPositionController::class);
        $this->assertSame(200, $controller->save($request)->getStatusCode());
        $this->assertNull($this->state()->last_watch_at);
        $request->attributes->set('auth_session_token_id', 1);
        $this->assertSame(200, $controller->save($request)->getStatusCode());
        $this->assertNotNull($this->state()->last_watch_at);
        $this->assertSame(40000, DB::table('engagement_content')->value('position_ms'));
    }

    public function test_account_erasure_removes_new_personal_data_even_with_tracking_off(): void
    {
        $this->delivery();
        config(['engagement.tracking_enabled' => false]);
        app(EngagementTracker::class)->eraseUser('u1');
        foreach (['engagement_users', 'engagement_content', 'engagement_deliveries'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    private function activity(string $at): void
    {
        app(EngagementTracker::class)->events('u1', [['name' => 'app_opened', 'occurred_at' => $at, 'properties' => []]]);
    }

    private function progress(string $id, string $at, int $position = 40): void
    {
        app(EngagementTracker::class)->playback('u1', $id.':'.$at, [
            'started_at' => $at, 'ended_at' => $at, 'content' => ['type' => 'movie', 'id' => $id],
            'timing' => ['watched_ms' => $position * 1000, 'duration_ms' => 100000, 'watch_position_ms' => $position * 1000],
            'result' => ['completed' => $position >= 90],
        ]);
    }

    private function state(): object
    {
        return DB::table('engagement_users')->where('user_id', 'u1')->first();
    }

    private function candidate(): ?array
    {
        return app(EngagementRules::class)->candidate($this->state(), CarbonImmutable::now('UTC'));
    }

    private function delivery(): int
    {
        $this->progress('m1', '2026-11-17T12:00:00Z');
        $candidate = $this->candidate();

        return DB::table('engagement_deliveries')->insertGetId([
            'user_id' => 'u1', 'dedupe_key' => app(EngagementRules::class)->dedupe('u1', $candidate),
            ...array_diff_key($candidate, ['eligible_at' => true]), 'scheduled_at' => CarbonImmutable::now('UTC'),
            'created_at' => CarbonImmutable::now('UTC'), 'updated_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function send(int $id, FCMNotificationController $push): void
    {
        (new SendSmartEngagement($id))->handle(app(EngagementRules::class), app(EngagementTiming::class), app(ExistingPushActivity::class), $push);
    }
}
