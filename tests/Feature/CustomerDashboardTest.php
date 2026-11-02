<?php

namespace Tests\Feature;

use App\Models\SessionTokenModel;
use App\Support\CustomerPaymentHistory;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.analytics' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sqlite');
        DB::purge('analytics');
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00'));
        Schema::create('user', function (Blueprint $t) {
            $t->increments('num');
            $t->string('uid');
            $t->string('name');
            $t->string('username')->nullable();
        });
        Schema::create('session_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('user_id');
            $t->string('device_id');
            $t->string('access_token');
            $t->string('refresh_token');
            $t->dateTime('access_expires_at');
            $t->dateTime('refresh_expires_at');
            $t->timestamps();
        });
        foreach (['alice', 'bob'] as $uid) {
            DB::table('user')->insert(['uid' => $uid, 'name' => ucfirst($uid), 'username' => $uid.'_01']);
            SessionTokenModel::create(['user_id' => $uid, 'device_id' => $uid.'-phone',
                'access_token' => SessionTokenModel::digest($uid.'-token'), 'refresh_token' => 'refresh-'.$uid,
                'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDay()]);
        }
        Schema::create('movie', function (Blueprint $t) {
            $t->increments('num');
            $t->string('id');
            $t->string('title');
            $t->string('poster')->nullable();
            $t->string('cover_img')->nullable();
        });
        DB::table('movie')->insert([['id' => 'film-a', 'title' => 'Alpha'], ['id' => 'film-b', 'title' => 'Beta']]);
        Schema::create('seasons', function (Blueprint $t) {
            $t->id();
            $t->string('movie_id');
        });
        DB::table('seasons')->insert(['id' => 1, 'movie_id' => 'film-a']);
        Schema::create('episodes', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->integer('season_id');
            $t->string('title');
            $t->string('thumbnail')->nullable();
        });
        DB::table('episodes')->insert(['id' => 'episode-a', 'season_id' => 1, 'title' => 'Episode One']);
        Schema::create('watch_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('user_id');
            $t->string('movie_id')->nullable();
            $t->string('episode_id')->nullable();
            $t->integer('seconds_watched');
            $t->timestamps();
        });
        foreach ([['alice', 'film-a', null, 120, '2026-11-01 08:00:00'], ['alice', 'film-b', null, 240, '2026-11-01 09:00:00'],
            ['alice', 'film-a', 'episode-a', 180, '2026-11-01 10:00:00'], ['alice', 'film-a', null, 600, '2026-10-31 09:00:00'],
            ['bob', 'film-a', null, 9999, '2026-11-01 09:00:00']] as [$uid, $movie, $episode, $seconds, $date]) {
            DB::table('watch_sessions')->insert(['user_id' => $uid, 'movie_id' => $movie, 'episode_id' => $episode,
                'seconds_watched' => $seconds, 'created_at' => $date]);
        }
        Schema::create('bandwidth_logs', function (Blueprint $t) {
            $t->id();
            $t->string('user_id');
            $t->string('movie_id');
            $t->string('episode_id')->nullable();
            $t->double('mb_used');
            $t->timestamps();
        });
        DB::table('bandwidth_logs')->insert(['user_id' => 'alice', 'movie_id' => 'film-a', 'mb_used' => 12.5, 'created_at' => '2026-11-01 08:00:00']);
        Schema::create('n_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('device_type');
            $t->decimal('price');
            $t->integer('duration_days');
        });
        DB::table('n_plans')->insert(['id' => 1, 'name' => 'Weekly Mobile', 'device_type' => 'mobile', 'price' => 99, 'duration_days' => 7]);
        Schema::create('n_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->string('user_id');
            $t->integer('plan_id');
            $t->dateTime('start_at');
            $t->dateTime('end_at');
            $t->boolean('is_active');
        });
        DB::table('n_subscriptions')->insert(['id' => 1, 'user_id' => 'alice', 'plan_id' => 1, 'start_at' => '2026-10-01', 'end_at' => '2026-10-07', 'is_active' => false]);
        Schema::create('n_devices', function (Blueprint $t) {
            $t->id();
            $t->string('user_id');
            $t->string('device_name');
            $t->string('device_type');
            $t->string('device_token');
            $t->dateTime('last_activity')->nullable();
            $t->string('status');
            $t->boolean('is_owner_device');
        });
        DB::table('n_devices')->insert(['user_id' => 'alice', 'device_name' => 'My phone', 'device_type' => 'mobile',
            'device_token' => 'private-device-secret', 'status' => 'active', 'is_owner_device' => true]);
        Schema::create('n_payment_histories', function (Blueprint $t) {
            $t->id();
            $t->string('user_id');
            $t->integer('plan_id')->nullable();
            $t->integer('subscription_id')->nullable();
            $t->string('app_payment_type')->nullable();
            $t->string('device_type')->nullable();
            $t->decimal('amount', 10, 2);
            $t->string('currency')->nullable();
            $t->string('payment_method')->nullable();
            $t->string('payment_gateway')->nullable();
            $t->string('transaction_id')->nullable();
            $t->string('status');
            $t->string('payment_type')->nullable();
            $t->dateTime('payment_date')->nullable();
            $t->dateTime('expiry_date')->nullable();
            $t->text('meta')->nullable();
            $t->timestamps();
        });
        $this->payment(1, 'alice', 'success');
        $this->payment(2, 'alice', 'pending');
        $this->payment(3, 'alice', 'failed');
        $this->payment(4, 'bob', 'success');
        $this->payment(5, 'alice', 'success', ['app_payment_type' => 'ppv']);
        $this->payment(6, 'alice', 'success', ['payment_date' => '2026-10-05 08:00:00', 'expiry_date' => '2026-10-11']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payment(int $id, string $uid, string $status, array $extra = []): void
    {
        DB::table('n_payment_histories')->insert(array_merge(['id' => $id, 'user_id' => $uid, 'status' => $status,
            'plan_id' => 1, 'subscription_id' => 1, 'app_payment_type' => 'subscription', 'device_type' => 'mobile',
            'amount' => 99, 'currency' => 'INR', 'payment_type' => 'renew', 'payment_method' => 'upi', 'payment_gateway' => 'razorpay',
            'transaction_id' => 'transaction-'.$id, 'payment_date' => '2026-11-01 08:00:00', 'expiry_date' => '2026-11-07',
            'meta' => json_encode(['private_gateway_secret' => 'must-not-leak']), 'created_at' => '2026-11-01 08:00:00'], $extra));
    }

    public function test_every_section_and_download_requires_identity(): void
    {
        foreach (['overview', 'watching', 'account', 'payments', 'payments/export', 'payments/1/receipt'] as $path) {
            $this->getJson('/account/stats/'.$path.'?auth_user_id=alice')->assertUnauthorized();
            $this->getJson('/api/v4/account/stats/'.$path)->assertUnauthorized();
        }
    }

    public function test_date_ranges_search_type_and_paging_preserve_summary_and_account_isolation(): void
    {
        $base = '/api/v4/account/stats/';
        $this->withToken('alice-token')->getJson($base.'overview?period=today&auth_user_id=bob')
            ->assertOk()->assertJsonPath('data.profile.username', 'alice_01')->assertJsonPath('data.stats.watch_seconds', 540)
            ->assertJsonPath('data.stats.viewed_titles', 3)->assertJsonPath('data.stats.activity.0.seconds_watched', 540);
        $this->getJson($base.'watching?period=last_month')->assertJsonPath('data.watch_seconds', 600)->assertJsonPath('data.period.to', '2026-10-31');
        $this->getJson($base.'watching?period=week')->assertJsonPath('data.watch_seconds', 1140);
        $this->getJson($base.'watching?period=today&q=beta&per_page=1')->assertJsonPath('data.content_pagination.total', 1)
            ->assertJsonPath('data.content_usage.0.title', 'Beta')->assertJsonPath('data.watch_seconds', 540);
        $this->getJson($base.'watching?period=today&type=episode')->assertJsonPath('data.content_usage.0.subtitle', 'Episode One')
            ->assertJsonPath('data.content_usage.0.watch_url', 'https://app.zostream.in/watch/film-a');
        $this->getJson($base.'watching?period=today&sort=watch_time&per_page=1&page=2')->assertJsonPath('data.content_usage.0.type', 'episode');
        $this->getJson($base.'watching?period=custom&from=2026-11-02&to=2026-11-01')->assertUnprocessable();
        $this->getJson($base.'watching?period=custom&from=2020-01-01&to=2026-11-01')->assertUnprocessable();
    }

    public function test_payment_history_filters_and_exports_do_not_include_other_users_or_ppv(): void
    {
        $this->withSession(['customer_web_user_id' => 'alice'])->getJson('/account/stats/payments?auth_user_id=bob')->assertOk()
            ->assertJsonPath('pagination.total', 3)->assertDontSee('must-not-leak');
        $this->getJson('/account/stats/payments?status=paid&device=mobile')->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.id', 1)->assertJsonPath('items.0.receipt_available', true);
        $this->getJson('/account/stats/payments?period=last_month')->assertJsonPath('items.0.id', 6);
        $this->getJson('/account/stats/account')->assertOk()->assertJsonPath('subscriptions.0.status', 'expired')
            ->assertDontSee('private-device-secret');
        $csv = $this->get('/account/stats/payments/export?status=paid')->assertOk()->streamedContent();
        $this->assertStringContainsString('transaction-1', $csv);
        $this->assertStringNotContainsString('transaction-4', $csv);
        $this->assertStringNotContainsString('transaction-5', $csv);
        $this->assertStringNotContainsString('transaction-6', $csv);
        $this->assertStringNotContainsString('must-not-leak', $csv);
    }

    public function test_receipt_download_is_owned_paid_and_a_real_pdf(): void
    {
        $this->withToken('alice-token')->getJson('/api/v4/account/stats/payments/4/receipt')->assertNotFound();
        $this->getJson('/api/v4/account/stats/payments/2/receipt')->assertStatus(409);
        $this->getJson('/api/v4/account/stats/payments/3/receipt')->assertStatus(409);
        $this->getJson('/api/v4/account/stats/payments/5/receipt')->assertNotFound();
        $pdf = $this->get('/api/v4/account/stats/payments/1/receipt')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->withSession(['customer_web_user_id' => 'alice'])->getJson('/account/stats/payments/4/receipt?auth_user_id=bob')->assertNotFound();
    }

    public function test_csv_includes_all_filtered_pages_and_custom_dates_apply_to_both_transports(): void
    {
        foreach (range(10, 34) as $id) {
            $this->payment($id, 'alice', 'success');
        }
        $this->withToken('alice-token')->getJson('/api/v4/account/stats/payments?status=paid&per_page=1')
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.pagination.total', 26);
        $csv = $this->get('/api/v4/account/stats/payments/export?status=paid&per_page=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('transaction-1', $csv);
        $this->assertStringContainsString('transaction-34', $csv);
        $query = '?period=custom&from=2026-10-01&to=2026-10-31';
        $this->getJson('/api/v4/account/stats/payments'.$query)->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.id', 6);
        $this->withSession(['customer_web_user_id' => 'alice'])->getJson('/account/stats/watching'.$query)
            ->assertOk()->assertJsonPath('watch_seconds', 600);
    }

    public function test_csv_escapes_spreadsheet_formulas_and_record_validity_does_not_follow_subscription(): void
    {
        foreach (['=1+1', ' @SUM(A1)', "\t=1", '+CMD', '-10'] as $value) {
            $this->assertStringStartsWith("'", CustomerPaymentHistory::csvCell($value));
        }
        DB::table('n_plans')->where('id', 1)->update(['name' => '=1+1']);
        DB::table('n_subscriptions')->where('id', 1)->update(['end_at' => '2030-01-01']);
        $this->withToken('alice-token')->getJson('/api/v4/account/stats/payments?status=paid')
            ->assertJsonPath('data.items.0.valid_until', '2026-11-07');
        $csv = $this->get('/api/v4/account/stats/payments/export?status=paid')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
    }

    public function test_analytics_only_viewing_has_consistent_totals_and_chart_without_double_counting(): void
    {
        Schema::connection('analytics')->create('playback_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('user_id');
            $t->string('content_id');
            $t->string('content_type');
            $t->integer('watched_ms');
            $t->text('metrics_json');
            $t->boolean('completed');
            $t->dateTime('ended_at');
        });
        foreach ([['film-a', 120000], ['film-new', 60000]] as [$id, $milliseconds]) {
            DB::connection('analytics')->table('playback_sessions')->insert(['user_id' => 'alice', 'content_id' => $id, 'content_type' => 'movie',
                'watched_ms' => $milliseconds, 'metrics_json' => '{"quality":{"bytes_transferred":0}}', 'completed' => false, 'ended_at' => '2026-11-01 03:00:00']);
        }
        $this->withToken('alice-token')->getJson('/api/v4/account/stats/overview?period=today')->assertOk()
            ->assertJsonPath('data.stats.watch_seconds', 600)->assertJsonPath('data.stats.activity.0.seconds_watched', 600)
            ->assertJsonPath('data.stats.bandwidth_mb', 0);
    }
    public function test_tab_filters_share_aggregation_but_users_and_dates_remain_separate(): void
    {
        $stats = app(\App\Http\Controllers\Api\V4\CustomerStatsController::class);
        $db = DB::connection();
        $db->enableQueryLog();
        $first = $stats->dataForUser('alice', 1, 10, ['period' => 'today']);
        $this->assertSame(540, $first['watch_seconds']);
        $this->assertNotEmpty($db->getQueryLog());
        $db->flushQueryLog();

        Carbon::setTestNow(now()->addSecond());
        $filtered = $stats->dataForUser('alice', 1, 1, ['period' => 'today', 'q' => 'beta']);
        $this->assertSame([], $db->getQueryLog(), 'Changing tabs/search must not re-run aggregation queries.');
        $this->assertSame('Beta', $filtered['content_usage'][0]['title']);
        $this->assertSame(1, $filtered['content_pagination']['total']);
        $this->assertSame(540, $filtered['watch_seconds']);
        $this->assertSame(9999, $stats->dataForUser('bob', 1, 10, ['period' => 'today'])['watch_seconds']);
        $this->assertSame(600, $stats->dataForUser('alice', 1, 10, ['period' => 'last_month'])['watch_seconds']);

        DB::table('watch_sessions')->where('user_id', 'alice')->where('seconds_watched', 120)->update(['seconds_watched' => 180]);
        $this->assertSame(540, $stats->dataForUser('alice', 1, 10, ['period' => 'today'])['watch_seconds']);
        Carbon::setTestNow(now()->addSeconds(31));
        $this->assertSame(600, $stats->dataForUser('alice', 1, 10, ['period' => 'today'])['watch_seconds']);
        $db->disableQueryLog();
    }

    public function test_overview_reads_continue_watching_in_bounded_batches_and_skips_hidden_or_completed_titles(): void
    {
        Schema::table('movie', fn (Blueprint $t) => $t->boolean('isAgeRestricted')->default(false));
        DB::table('movie')->insert(['id' => 'restricted', 'title' => 'Restricted', 'isAgeRestricted' => true]);
        Schema::create('watch_position', function (Blueprint $t) {
            $t->increments('num');
            $t->string('user_id');
            $t->string('movie_id');
            $t->string('movie_type');
            $t->integer('position');
            $t->integer('duration')->nullable();
            $t->timestamps();
        });
        foreach (range(1, 142) as $id) {
            DB::table('watch_position')->insert(['num' => $id, 'user_id' => $id === 142 ? 'bob' : 'alice',
                'movie_id' => $id > 100 && $id <= 140 ? 'restricted' : 'film-a', 'movie_type' => 'movie',
                'position' => $id === 141 ? 90 : 20, 'duration' => 100, 'updated_at' => now()]);
        }
        DB::enableQueryLog();
        $response = $this->withSession(['customer_web_user_id' => 'alice'])->getJson('/account/stats/overview?period=today')
            ->assertOk()->assertJsonCount(4, 'continue_watching')->assertJsonPath('continue_unavailable', false);
        $this->assertSame([100, 99, 98, 97], array_column($response->json('continue_watching'), 'id'));
        $batches = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from "watch_position"')
            && str_contains($query['query'], 'limit 32'));
        $this->assertCount(2, $batches, 'Stop loading history once four visible unfinished titles are found.');
        DB::disableQueryLog();
    }

    public function test_stats_indexes_are_idempotent_and_rollback_only_their_own_indexes(): void
    {
        $migration = require database_path('migrations/2026_11_02_000001_add_customer_stats_indexes.php');
        Schema::table('watch_sessions', fn (Blueprint $t) => $t->index(['user_id', 'created_at'], 'existing_watch_idx'));
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasIndex('bandwidth_logs', 'stats_bandwidth_user_created_idx'));
        $this->assertFalse(Schema::hasIndex('watch_sessions', 'stats_watch_user_created_idx'));
        $migration->down();
        $this->assertTrue(Schema::hasIndex('watch_sessions', 'existing_watch_idx'));
        $this->assertFalse(Schema::hasIndex('bandwidth_logs', 'stats_bandwidth_user_created_idx'));

        Schema::connection('analytics')->create('playback_sessions', function (Blueprint $t) {
            $t->id(); $t->string('user_id'); $t->dateTime('ended_at');
        });
        $analyticsMigration = require database_path('migrations/2026_11_02_000002_add_customer_stats_analytics_index.php');
        $analyticsMigration->up();
        $analyticsMigration->up();
        $this->assertTrue(Schema::connection('analytics')->hasIndex('playback_sessions', 'stats_playback_user_ended_idx'));
        $analyticsMigration->down();
        $this->assertFalse(Schema::connection('analytics')->hasIndex('playback_sessions', 'stats_playback_user_ended_idx'));
    }

}
