<?php

namespace Tests\Feature;

use App\Services\NexframeBusinessAnalytics;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NexframeMetricAccuracyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.nexframe_dashboard.business_cache_store', 'array');
        $this->travelTo(Carbon::parse('2026-10-16 12:00:00'));
        config()->set('database.connections.analytics', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set('services.nexframe_dashboard.instagram', []);
        config()->set('services.nexframe_dashboard.facebook', []);
        config()->set('services.nexframe_dashboard.partnership_started_at', '2026-10-14');
        Storage::fake('local');
        Http::preventStrayRequests();
        Schema::create('user', function (Blueprint $table) {
            $table->id('num');
            $table->string('uid');
            $table->dateTime('created_date');
        });
        Schema::create('n_payment_histories', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('status');
            $table->decimal('amount');
            $table->string('app_payment_type');
            $table->dateTime('payment_date')->nullable();
            $table->timestamps();
        });
        Schema::connection('analytics')->create('playback_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable();
            $table->dateTime('started_at');
            $table->bigInteger('watched_ms');
        });
        DB::table('user')->insert([
            ['uid' => 'old', 'created_date' => '2026-09-01 00:00:00'],
            ['uid' => 'new1', 'created_date' => '2026-10-14 00:00:00'],
            ['uid' => 'new2', 'created_date' => '2026-10-14 00:00:00'],
        ]);
        foreach ([
            ['old', '2026-10-01', 'success', 100, 'subscription'],
            ['old', '2026-10-14', 'success', 100, 'subscription'],
            ['new1', '2026-10-14', 'success', 100, 'subscription'],
            ['new1', '2026-10-14', 'success', 100, 'subscription'],
            ['new2', '2026-10-14', 'pending', 100, 'subscription'],
            ['new2', '2026-10-14', 'success', 0, 'subscription'],
            ['new2', '2026-10-14', 'success', 100, 'ppv'],
            ['new2', '2026-10-17', 'success', 100, 'subscription'],
        ] as [$user, $date, $status, $amount, $type]) {
            DB::table('n_payment_histories')->insert(['user_id' => $user, 'status' => $status, 'amount' => $amount, 'app_payment_type' => $type, 'payment_date' => $date.' 10:00:00', 'created_at' => $date.' 09:00:00']);
        }
        DB::connection('analytics')->table('playback_sessions')->insert([
            ['user_id' => 'a', 'started_at' => '2026-10-14 10:00:00', 'watched_ms' => 3600000],
            ['user_id' => 'a', 'started_at' => '2026-10-14 11:00:00', 'watched_ms' => 3600000],
            ['user_id' => 'a', 'started_at' => '2026-10-15 10:00:00', 'watched_ms' => 3600000],
            ['user_id' => 'b', 'started_at' => '2026-10-15 10:00:00', 'watched_ms' => 3600000],
        ]);
    }

    public function test_payments_and_conversion_exclude_old_users_pending_free_and_ppv_payments(): void
    {
        $service = app(NexframeBusinessAnalytics::class);
        $from = Carbon::parse('2026-10-14');
        $to = Carbon::parse('2026-10-16')->endOfDay();
        $payments = $service->subscriptions($from, $to);
        $this->assertSame(1, $payments['first_time_payers']);
        $this->assertSame(2, $payments['repeat_payments']);
        $this->assertSame(3, $payments['successful_payments']);
        $this->assertSame(2, $payments['paying_users']);
        $this->assertSame(['registrations' => 2, 'converted_users' => 1, 'rate' => 50.0], $service->registrationConversion($from, $to));
        $streaming = $service->streaming($from, $to);
        $this->assertSame(2, $streaming['viewers']);
        $this->assertSame(1.0, $streaming['average_daily_viewers']);
        $this->assertSame(4.0, $streaming['watch_hours']);
    }

    public function test_comparison_modes_calendar_days_and_daily_payment_counts(): void
    {
        $response = $this->withSession(['nexframe_dashboard_authenticated' => true])->getJson('/analytics/nexframe/data?from=2026-10-15&to=2026-10-16');
        $response->assertOk()->assertJsonPath('range.days', 2)
            ->assertJsonPath('impact.partnership.days', 3)
            ->assertJsonPath('impact.partnership.baseline_range.from', '2026-10-11')
            ->assertJsonPath('impact.partnership.since.subscriptions', 1)
            ->assertJsonPath('impact.partnership.daily_averages.since.streaming_users', 1)
            ->assertJsonPath('impact.registration_cohort.rate', null);
        $this->getJson('/analytics/nexframe/data?from=2026-10-14&to=2026-10-16&comparison=selected')->assertOk()
            ->assertJsonPath('impact.partnership.mode', 'selected')
            ->assertJsonPath('daily.0.subscriptions', 1)
            ->assertJsonPath('impact.registration_cohort.rate', 50)
            ->assertJsonPath('range.includes_today', true);
        $this->getJson('/analytics/nexframe/data?from=2026-10-16&to=2026-10-16&comparison=selected')->assertOk()
            ->assertJsonPath('impact.partnership.days', 1)
            ->assertJsonPath('impact.partnership.baseline_range.from', '2026-10-15');
    }

    public function test_missing_sources_return_null_instead_of_zero(): void
    {
        Schema::drop('n_payment_histories');
        Schema::connection('analytics')->drop('playback_sessions');
        $this->withSession(['nexframe_dashboard_authenticated' => true])->getJson('/analytics/nexframe/data?from=2026-10-14&to=2026-10-16')->assertOk()
            ->assertJsonPath('source_status.payments', false)->assertJsonPath('source_status.streaming', false)
            ->assertJsonPath('metrics.subscriptions.value', null)->assertJsonPath('metrics.streaming_users.value', null)
            ->assertJsonPath('daily.0.streaming_users', null)->assertJsonPath('daily.0.subscriptions', null);
    }

    public function test_successful_legacy_payment_uses_created_date_when_payment_date_is_null(): void
    {
        DB::table('n_payment_histories')->insert(['user_id' => 'legacy', 'status' => 'success', 'amount' => 100, 'app_payment_type' => 'subscription', 'payment_date' => null, 'created_at' => '2026-10-14 12:00:00']);
        $this->withSession(['nexframe_dashboard_authenticated' => true])->getJson('/analytics/nexframe/data?from=2026-10-14&to=2026-10-16')->assertOk()
            ->assertJsonPath('metrics.subscriptions.value', 2)
            ->assertJsonPath('daily.0.subscriptions', 2);
    }

    public function test_warm_dashboard_and_social_refresh_do_not_query_either_database(): void
    {
        $url = '/analytics/nexframe/data?from=2026-10-14&to=2026-10-16';
        $this->withSession(['nexframe_dashboard_authenticated' => true])->getJson($url)->assertOk()
            ->assertJsonPath('business_snapshot.refresh_interval_seconds', 300);
        DB::connection()->enableQueryLog();
        DB::connection('analytics')->enableQueryLog();
        DB::connection()->flushQueryLog();
        DB::connection('analytics')->flushQueryLog();

        $this->getJson($url.'&refresh=1')->assertOk()->assertJsonPath('metrics.streaming_users.value', 2);
        $this->assertSame([], DB::connection()->getQueryLog());
        $this->assertSame([], DB::connection('analytics')->getQueryLog());
    }

    public function test_business_cache_refreshes_after_five_minutes(): void
    {
        $url = '/analytics/nexframe/data?from=2026-10-14&to=2026-10-16';
        $this->withSession(['nexframe_dashboard_authenticated' => true])->getJson($url)->assertOk()
            ->assertJsonPath('metrics.streaming_users.value', 2);
        DB::connection('analytics')->table('playback_sessions')->insert([
            'user_id' => 'new-viewer', 'started_at' => '2026-10-16 11:00:00', 'watched_ms' => 3600000,
        ]);
        $this->getJson($url)->assertOk()->assertJsonPath('metrics.streaming_users.value', 2);
        $this->travel(6)->minutes();
        $this->getJson($url)->assertOk()->assertJsonPath('metrics.streaming_users.value', 3);
    }

    public function test_existing_watch_duration_logs_supply_viewers_and_hours_without_fake_session_counts(): void
    {
        Schema::connection('analytics')->drop('playback_sessions');
        Schema::create('watch_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->integer('seconds_watched');
            $table->dateTime('created_at');
        });
        DB::table('watch_sessions')->insert([
            ['user_id' => 'a', 'seconds_watched' => 1800, 'created_at' => '2026-10-14 10:00:00'],
            ['user_id' => 'a', 'seconds_watched' => 1800, 'created_at' => '2026-10-15 10:00:00'],
            ['user_id' => 'b', 'seconds_watched' => 3600, 'created_at' => '2026-10-15 11:00:00'],
        ]);
        $this->withSession(['nexframe_dashboard_authenticated' => true])->getJson('/analytics/nexframe/data?from=2026-10-14&to=2026-10-16')->assertOk()
            ->assertJsonPath('streaming_source.id', 'watch_sessions')
            ->assertJsonPath('source_status.streaming', true)
            ->assertJsonPath('metrics.streaming_users.value', 2)
            ->assertJsonPath('metrics.watch_hours.value', 2)
            ->assertJsonPath('metrics.streaming_sessions.value', null)
            ->assertJsonPath('metrics.average_daily_viewers.value', 1)
            ->assertJsonPath('daily.1.streaming_users', 2);
    }

    public function test_an_empty_playback_source_does_not_switch_to_a_different_source(): void
    {
        DB::connection('analytics')->table('playback_sessions')->delete();
        $streaming = app(NexframeBusinessAnalytics::class)->streaming(Carbon::parse('2026-10-14'), Carbon::parse('2026-10-16')->endOfDay());
        $this->assertTrue($streaming['available']);
        $this->assertSame('playback_sessions', $streaming['source']);
        $this->assertSame(0, $streaming['viewers']);
        $this->assertSame(0, $streaming['sessions']);
    }
}
