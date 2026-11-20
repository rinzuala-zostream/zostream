<?php

namespace Tests\Feature;

use App\Models\New\Devices;
use App\Models\New\PaymentHistory;
use App\Models\New\Plan;
use App\Models\New\Subscription;
use App\Models\RedemptionCode;
use App\Models\SessionTokenModel;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RedemptionCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00'));
        (require database_path('migrations/2026_11_20_000001_create_redemption_codes_table.php'))->up();
        (require database_path('migrations/2026_11_20_000002_add_print_batches_to_redemption_codes.php'))->up();
        (require database_path('migrations/2026_11_20_000003_add_ppv_content_type_to_redemption_codes.php'))->up();
        Schema::create('n_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('device_type');
            $t->integer('duration_days');
            $t->decimal('price')->default(0);
            $t->boolean('is_active');
            $t->timestamps();
        });
        Schema::create('n_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->string('user_id');
            $t->unsignedBigInteger('plan_id');
            $t->dateTime('start_at');
            $t->dateTime('end_at');
            $t->boolean('is_active');
            $t->timestamps();
        });
        Schema::create('n_devices', function (Blueprint $t) {
            $t->id();
            $t->string('user_id');
            $t->unsignedBigInteger('subscription_id')->nullable();
            $t->string('device_name')->nullable();
            $t->string('device_type');
            $t->string('device_token');
            $t->boolean('is_owner_device')->default(false);
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('n_payment_histories', function (Blueprint $t) {
            $t->id();
            $t->string('user_id');
            $t->unsignedBigInteger('subscription_id')->nullable();
            $t->unsignedBigInteger('plan_id')->nullable();
            $t->string('movie_id')->nullable();
            foreach (['device_type', 'app_payment_type', 'currency', 'payment_method', 'payment_gateway', 'status', 'payment_type'] as $name) {
                $t->string($name);
            }
            $t->string('transaction_id')->unique();
            $t->decimal('amount');
            $t->dateTime('payment_date');
            $t->dateTime('expiry_date');
            $t->json('meta');
            $t->timestamps();
        });
        Schema::create('movie', function (Blueprint $t) {
            $t->id('num');
            $t->string('id');
            $t->string('title');
            $t->string('poster')->nullable();
            $t->string('status');
            $t->boolean('isEnable');
            $t->boolean('isPayPerView');
        });
        Schema::create('seasons', function (Blueprint $t) {
            $t->id('num');
            $t->string('id');
            $t->unsignedBigInteger('movie_id');
            $t->string('title');
            $t->integer('season_number');
            $t->string('poster')->nullable();
            $t->boolean('isPayPerView');
            $t->string('status');
        });
        Schema::create('episodes', function (Blueprint $t) {
            $t->id('num');
            $t->string('id');
            $t->string('season_id');
            $t->string('title');
            $t->integer('episode_number');
            $t->string('thumbnail')->nullable();
            $t->boolean('isPayPerView');
            $t->boolean('is_active');
            $t->string('status');
            $t->boolean('isPremium')->default(false);
        });
        Schema::create('session_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('user_id');
            $t->string('device_id');
            $t->string('access_token');
            $t->dateTime('access_expires_at');
            $t->timestamps();
        });
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('admin_uid');
        });
        Schema::create('user', function (Blueprint $t) {
            $t->id('num');
            $t->string('uid');
            $t->string('auth_phone')->nullable();
        });
        DB::table('admin_users')->insert(['admin_uid' => 'admin']);
        DB::table('movie')->insert(['id' => 'film', 'title' => 'Film', 'status' => 'Published', 'isEnable' => true, 'isPayPerView' => true]);
        Plan::create(['id' => 1, 'name' => 'TV', 'device_type' => 'tv', 'duration_days' => 90, 'is_active' => true]);
        foreach (['alice', 'bob', 'admin'] as $uid) {
            SessionTokenModel::create(['user_id' => $uid, 'device_id' => $uid.'-phone', 'access_token' => SessionTokenModel::digest($uid), 'access_expires_at' => now()->addHour()]);
            Devices::create(['user_id' => $uid, 'device_token' => $uid.'-phone', 'device_type' => 'mobile', 'is_owner_device' => true, 'status' => 'active']);
        }
        DB::table('n_devices')->insert(['id' => 10, 'user_id' => 'alice', 'device_token' => 'alice-tv', 'device_name' => 'TV', 'device_type' => 'tv', 'status' => 'inactive']);
        DB::table('n_devices')->insert(['id' => 11, 'user_id' => 'bob', 'device_token' => 'bob-tv', 'device_type' => 'tv', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function headersFor(string $uid): array
    {
        return ['Authorization' => 'Bearer '.$uid, 'X-Client-Platform' => 'android'];
    }

    private function code(string $type = 'ppv', array $extra = []): RedemptionCode
    {
        return RedemptionCode::create([
            'label' => 'Gift', 'token' => bin2hex(random_bytes(32)), 'created_by' => 'admin',
            'type' => $type, 'movie_id' => $type === 'ppv' ? 'film' : null,
            'plan_id' => $type === 'subscription' ? 1 : null, ...$extra,
        ])->fresh();
    }

    private function redeem(RedemptionCode $code, int $device = 10, string $user = 'alice')
    {
        return $this->postJson('/api/v4/redemptions/'.$code->token.'/redeem', ['device_id' => $device], $this->headersFor($user));
    }

    public function test_scan_does_not_consume_code_and_only_lists_own_devices(): void
    {
        $code = $this->code('subscription');
        $this->getJson('/api/v4/redemptions/'.$code->token, $this->headersFor('alice'))
            ->assertOk()->assertJsonCount(1, 'data.devices')->assertJsonPath('data.devices.0.id', 10)
            ->assertJsonPath('data.extension_days', 30);
        $this->assertSame('available', $code->fresh()->status);
        $this->assertSame(0, PaymentHistory::count());
    }

    public function test_admin_can_issue_a_plan_free_subscription_and_activate_30_days(): void
    {
        $monthly = Plan::create(['name' => 'Monthly TV', 'device_type' => 'tv', 'duration_days' => 30, 'is_active' => true]);
        $response = $this->postJson('/api/v4/admin/redemption-codes', ['label' => '30 days', 'type' => 'subscription'], $this->headersFor('admin'))
            ->assertCreated()->assertJsonPath('data.plan_id', null);
        $code = RedemptionCode::findOrFail($response->json('data.id'));
        $this->getJson('/api/v4/redemptions/'.$code->token, $this->headersFor('alice'))
            ->assertOk()->assertJsonCount(2, 'data.devices')
            ->assertJsonPath('data.new_subscription_days', 30)->assertJsonPath('data.target.name', '30-day subscription');
        $this->redeem($code)->assertOk()->assertJsonPath('data.action', 'add_new')->assertJsonPath('data.plan_id', $monthly->id);
        $this->assertSame(Subscription::endAtForDuration(now(), 30)->toDateTimeString(), Subscription::first()->end_at->toDateTimeString());
        $this->assertSame($monthly->id, PaymentHistory::first()->plan_id);
    }

    public function test_plan_free_qr_extends_the_selected_devices_existing_plan(): void
    {
        $plan = Plan::create(['name' => 'Another TV plan', 'device_type' => 'tv', 'duration_days' => 90, 'is_active' => true]);
        $sub = Subscription::create(['user_id' => 'alice', 'plan_id' => $plan->id, 'start_at' => now(), 'end_at' => now()->addDays(12)->endOfDay(), 'is_active' => true]);
        Devices::find(10)->update(['subscription_id' => $sub->id]);
        $previous = $sub->fresh()->end_at->copy();
        $code = $this->code('subscription', ['plan_id' => null]);
        $this->redeem($code)->assertOk()->assertJsonPath('data.action', 'extend')->assertJsonPath('data.plan_id', $plan->id);
        $this->redeem($code)->assertOk()->assertJsonPath('data.already_redeemed', true);
        $this->assertSame($previous->addDays(30)->toDateTimeString(), $sub->fresh()->end_at->toDateTimeString());
        $this->assertSame($plan->id, $sub->fresh()->plan_id);
    }

    public function test_plan_free_qr_picks_a_monthly_plan_for_a_new_device_and_never_consumes_when_unavailable(): void
    {
        Plan::create(['name' => 'Long mobile', 'device_type' => 'mobile', 'duration_days' => 90, 'is_active' => true]);
        Plan::create(['name' => 'Inactive monthly mobile', 'device_type' => 'mobile', 'duration_days' => 30, 'is_active' => false]);
        $code = $this->code('subscription', ['plan_id' => null]);
        $this->redeem($code, 1)->assertUnprocessable();
        $this->assertSame('available', $code->fresh()->status);
        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, PaymentHistory::count());
        $this->getJson('/api/v4/redemptions/'.$code->token, $this->headersFor('alice'))
            ->assertOk()->assertJsonPath('data.devices.0.eligible', false)
            ->assertJsonPath('data.devices.0.reason', 'No active 30-day subscription plan is available for this device.');
        $plan = Plan::create(['name' => 'Monthly mobile', 'device_type' => 'mobile', 'duration_days' => 30, 'is_active' => true]);
        $this->redeem($code, 1)->assertOk()->assertJsonPath('data.plan_id', $plan->id);
        $this->assertSame('mobile', PaymentHistory::first()->device_type);
    }

    public function test_expired_generic_subscription_starts_30_days_even_if_old_plan_was_longer(): void
    {
        $sub = Subscription::create(['user_id' => 'alice', 'plan_id' => 1, 'start_at' => now()->subDays(100), 'end_at' => now()->subDay(), 'is_active' => true]);
        Devices::find(10)->update(['subscription_id' => $sub->id]);
        $code = $this->code('subscription', ['plan_id' => null]);
        $this->redeem($code)->assertUnprocessable();
        $this->assertSame('available', $code->fresh()->status);
        $this->assertSame($sub->id, Devices::find(10)->subscription_id);
        $monthly = Plan::create(['name' => 'Monthly TV', 'device_type' => 'tv', 'duration_days' => 30, 'is_active' => true]);
        $this->redeem($code)->assertOk()->assertJsonPath('data.action', 'add_new')->assertJsonPath('data.plan_id', $monthly->id);
        $this->assertSame($monthly->id, Devices::find(10)->subscription->plan_id);
        $this->assertSame(Subscription::endAtForDuration(now(), 30)->toDateTimeString(), Devices::find(10)->subscription->end_at->toDateTimeString());
    }

    public function test_a4_batch_has_12_unique_codes_per_page_and_is_safe_to_retry(): void
    {
        Plan::create(['name' => 'Monthly TV', 'device_type' => 'tv', 'duration_days' => 30, 'is_active' => true]);
        $body = ['label' => 'Campaign', 'type' => 'subscription', 'page_count' => 2, 'batch_id' => (string) \Illuminate\Support\Str::uuid()];
        $first = $this->postJson('/api/v4/admin/redemption-codes/batches', $body, $this->headersFor('admin'))
            ->assertCreated()->assertJsonPath('data.page_count', 2)->assertJsonPath('data.codes_per_page', 12)
            ->assertJsonCount(24, 'data.codes');
        $this->assertCount(24, array_unique(array_column($first->json('data.codes'), 'token')));
        $this->postJson('/api/v4/admin/redemption-codes/batches', $body, $this->headersFor('admin'))
            ->assertCreated()->assertJsonPath('data.codes.0.id', $first->json('data.codes.0.id'));
        $this->assertSame(24, RedemptionCode::count());
        $body['page_count'] = 1;
        $this->postJson('/api/v4/admin/redemption-codes/batches', $body, $this->headersFor('admin'))->assertConflict();
        $code = RedemptionCode::first();
        $this->redeem($code)->assertOk();
        $this->getJson('/api/v4/admin/redemption-codes/batches/'.$body['batch_id'], $this->headersFor('admin'))
            ->assertOk()->assertJsonPath('data.codes.0.status', 'redeemed')->assertJsonCount(24, 'data.codes');
    }

    public function test_batch_validation_permissions_and_atomic_failure(): void
    {
        $body = ['label' => 'Campaign', 'type' => 'ppv', 'movie_id' => 'film', 'page_count' => 1, 'batch_id' => (string) \Illuminate\Support\Str::uuid()];
        $this->postJson('/api/v4/admin/redemption-codes/batches', $body, $this->headersFor('alice'))->assertForbidden();
        $this->postJson('/api/v4/admin/redemption-codes/batches', ['label' => 'Generic only', 'type' => 'subscription', 'plan_id' => 1, 'batch_id' => $body['batch_id'], 'page_count' => 1], $this->headersFor('admin'))->assertUnprocessable();
        foreach ([0, 21, 1.5] as $count) {
            $this->postJson('/api/v4/admin/redemption-codes/batches', [...$body, 'page_count' => $count], $this->headersFor('admin'))->assertUnprocessable();
        }
        $this->postJson('/api/v4/admin/redemption-codes/batches', [...$body, 'movie_id' => 'missing'], $this->headersFor('admin'))->assertUnprocessable();
        $this->assertSame(0, DB::table('redemption_print_batches')->count());
        $created = 0;
        RedemptionCode::creating(function () use (&$created) {
            if (++$created === 3) {
                throw new \RuntimeException('Simulated batch failure');
            }
        });
        try {
            $this->postJson('/api/v4/admin/redemption-codes/batches', $body, $this->headersFor('admin'))->assertStatus(500);
            $this->assertSame(0, RedemptionCode::count());
            $this->assertSame(0, DB::table('redemption_print_batches')->count());
        } finally {
            RedemptionCode::flushEventListeners();
        }
    }

    public function test_existing_batch_can_be_recovered_after_its_qr_expiry(): void
    {
        $body = ['label' => 'Campaign', 'type' => 'subscription', 'page_count' => 1,
            'batch_id' => (string) \Illuminate\Support\Str::uuid(), 'expires_at' => now()->addMinute()->toIso8601String()];
        $this->postJson('/api/v4/admin/redemption-codes/batches', $body, $this->headersFor('admin'))->assertCreated();
        Carbon::setTestNow(now()->addMinutes(2));
        $this->postJson('/api/v4/admin/redemption-codes/batches', $body, $this->headersFor('admin'))
            ->assertCreated()->assertJsonPath('data.codes.0.status', 'expired');
        $this->assertSame(12, RedemptionCode::count());
        $body['batch_id'] = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v4/admin/redemption-codes/batches', $body, $this->headersFor('admin'))->assertUnprocessable();
        $this->assertSame(1, DB::table('redemption_print_batches')->count());
    }

    public function test_ppv_is_device_bound_single_use_and_retry_is_idempotent(): void
    {
        $code = $this->code('ppv', ['rental_days' => 5]);
        $this->redeem($code)->assertOk()->assertJsonPath('data.already_redeemed', false);
        $payment = PaymentHistory::firstOrFail();
        $this->assertSame('alice-tv', $payment->meta['device_token']);
        $this->assertSame('film', $payment->movie_id);
        $this->assertTrue($payment->expiry_date->equalTo(now()->addDays(5)));
        $this->redeem($code)->assertOk()->assertJsonPath('data.already_redeemed', true);
        $this->redeem($code, 11, 'bob')->assertConflict();
        $this->assertSame(1, PaymentHistory::count());
    }

    public function test_subscription_add_new_then_extends_current_expiry_by_exactly_30_days(): void
    {
        $this->redeem($this->code('subscription'))->assertOk()->assertJsonPath('data.action', 'add_new');
        $subscription = Subscription::firstOrFail();
        $this->assertSame(Subscription::endAtForDuration(now(), 90)->toDateTimeString(), $subscription->end_at->toDateTimeString());
        $previous = $subscription->end_at->copy();
        $code = $this->code('subscription');
        $this->redeem($code)->assertOk()->assertJsonPath('data.action', 'extend');
        $this->redeem($code)->assertOk();
        $this->assertSame($previous->addDays(30)->toDateTimeString(), $subscription->fresh()->end_at->toDateTimeString());
        $this->assertSame(1, Subscription::count());
        $this->assertSame($subscription->id, Devices::find(10)->subscription_id);
        $this->assertNull(Devices::find(11)->subscription_id);
    }

    public function test_expired_subscription_starts_a_new_period_today(): void
    {
        $sub = Subscription::create(['user_id' => 'alice', 'plan_id' => 1, 'start_at' => now()->subDays(100), 'end_at' => now()->subDay(), 'is_active' => true]);
        Devices::find(10)->update(['subscription_id' => $sub->id]);
        $this->redeem($this->code('subscription'))->assertOk()->assertJsonPath('data.action', 'add_new');
        $this->assertTrue(Devices::find(10)->subscription->start_at->equalTo(now()));
    }

    public function test_invalid_devices_and_incompatible_plans_leave_qr_available(): void
    {
        $code = $this->code('subscription');
        $this->redeem($code, 11)->assertNotFound();
        $this->redeem($code, 1)->assertUnprocessable();
        Devices::find(10)->update(['status' => 'blocked']);
        $this->redeem($code)->assertNotFound();
        $this->assertSame('available', $code->fresh()->status);
        $this->assertSame(0, PaymentHistory::count());
    }

    public function test_active_different_plan_is_not_overwritten(): void
    {
        Plan::create(['id' => 2, 'name' => 'Other TV', 'device_type' => 'tv', 'duration_days' => 30, 'is_active' => true]);
        $sub = Subscription::create(['user_id' => 'alice', 'plan_id' => 2, 'start_at' => now(), 'end_at' => now()->addDays(10), 'is_active' => true]);
        Devices::find(10)->update(['subscription_id' => $sub->id]);
        $code = $this->code('subscription');
        $this->redeem($code)->assertUnprocessable();
        $this->assertSame('available', $code->fresh()->status);
        $this->assertSame(2, $sub->fresh()->plan_id);
    }

    public function test_expired_cancelled_and_unpublished_codes_cannot_activate(): void
    {
        $this->redeem($this->code('ppv', ['expires_at' => now()->subMinute()]))->assertStatus(410);
        $this->redeem($this->code('ppv', ['status' => 'cancelled']))->assertConflict();
        DB::table('movie')->update(['status' => 'Draft']);
        $this->redeem($this->code())->assertUnprocessable();
        $this->assertSame(0, PaymentHistory::count());
    }

    public function test_only_admin_can_issue_cancel_and_read_history(): void
    {
        $this->getJson('/api/v4/admin/redemption-codes')->assertUnauthorized();
        $this->getJson('/api/v4/admin/redemption-codes', $this->headersFor('alice'))->assertForbidden();
        $response = $this->postJson('/api/v4/admin/redemption-codes', ['label' => 'Gift', 'type' => 'ppv', 'movie_id' => 'film'], $this->headersFor('admin'))->assertCreated();
        $this->assertStringStartsWith('zostream://redeem/', $response->json('data.qr_payload'));
        $id = $response->json('data.id');
        $this->getJson('/api/v4/admin/redemption-codes', $this->headersFor('admin'))->assertOk()->assertJsonCount(1, 'data.data');
        $this->postJson('/api/v4/admin/redemption-codes/'.$id.'/cancel', [], $this->headersFor('admin'))->assertOk();
        $this->assertSame('cancelled', RedemptionCode::find($id)->status);
    }

    public function test_owner_device_is_required_even_if_identity_is_spoofed_in_body(): void
    {
        $code = $this->code();
        $this->postJson('/api/v4/redemptions/'.$code->token.'/redeem', ['device_id' => 10, 'auth_user_id' => 'alice'])->assertUnauthorized();
        $this->postJson('/api/v4/redemptions/'.$code->token.'/redeem', ['device_id' => 10, 'auth_user_id' => 'alice'], $this->headersFor('bob'))->assertNotFound();
        Devices::where('device_token', 'alice-phone')->update(['is_owner_device' => false]);
        $this->redeem($code)->assertForbidden();
    }

    private function ppvPlaybackController(): \App\Http\Controllers\NewStreamController
    {
        Schema::create('n_active_streams', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('subscription_id')->nullable();
            $t->unsignedBigInteger('device_id');
            foreach (['device_type', 'content_type', 'content_key', 'stream_token', 'status'] as $name) {
                $t->string($name);
            }
            $t->unsignedBigInteger('content_id')->nullable();
            $t->dateTime('started_at');
            $t->dateTime('last_ping');
        });
        $movies = \Mockery::mock(\App\Http\Controllers\New\MovieController::class);
        $movies->shouldReceive('getLink')->andReturn(response()->json([
            'status' => 'success', 'title' => 'Film', 'links' => [['url' => 'https://media.example.test/film.mp4']],
        ]));
        $watch = \Mockery::mock(\App\Http\Controllers\WatchPositionController::class);
        $watch->shouldReceive('getWatchPosition')->andReturn(response()->json(['status' => 'success', 'watchPosition' => 0]));
        $ads = \Mockery::mock(\App\Http\Controllers\Api\V4\AdServingController::class);
        $ads->shouldReceive('serve')->andReturn(response()->json(['success' => true, 'data' => []]));
        $hls = \Mockery::mock(\App\Http\Controllers\HlsFolderController::class);
        $hls->shouldReceive('check')->andReturn(response()->json(['data' => ['stream_url' => 'https://media.example.test/film.m3u8']]));

        return new \App\Http\Controllers\NewStreamController($hls, $movies, $watch, $ads);
    }

    public function test_ppv_grants_play_only_on_the_selected_device_and_keep_existing_owner_rules(): void
    {
        $controller = $this->ppvPlaybackController();
        $start = function (string $token) use ($controller) {
            $request = \Illuminate\Http\Request::create('/', 'POST', [
                'user_id' => 'alice', 'movie_id' => 'film', 'type' => 'movie', 'platform' => 'ios', 'device_type' => 'tv',
            ]);
            $request->headers->set('Device-Token', $token);

            return $controller->start($request);
        };
        $this->redeem($this->code())->assertOk();
        $this->assertSame(200, $start('alice-tv')->getStatusCode());
        $this->assertFalse(Devices::find(10)->is_owner_device);
        $this->assertSame(403, $start('alice-phone')->getStatusCode());
        Devices::find(10)->update(['status' => 'blocked']);
        $this->assertSame(403, $start('alice-tv')->getStatusCode());
        Devices::find(10)->update(['status' => 'active']);

        // A later grant for another TV must not invalidate the first device.
        $other = Devices::create(['user_id' => 'alice', 'device_token' => 'alice-tv-2', 'device_type' => 'tv', 'status' => 'active']);
        $this->redeem($this->code(), $other->id)->assertOk();
        $this->assertSame(200, $start('alice-tv')->getStatusCode());
        $this->assertSame(200, $start('alice-tv-2')->getStatusCode());

        // A regular paid rental still cannot be played by a shared device.
        DB::table('redemption_codes')->delete();
        PaymentHistory::query()->update(['payment_gateway' => 'razorpay']);
        $this->assertSame(403, $start('alice-tv-2')->getStatusCode());
    }

    private function seedSeries(): void
    {
        DB::table('movie')->insert(['num' => 2, 'id' => 'series', 'title' => 'Demo Series', 'poster' => '/series.jpg', 'status' => 'Published', 'isEnable' => true, 'isPayPerView' => false]);
        foreach ([1, 2] as $season) {
            DB::table('seasons')->insert(['id' => 'season-'.$season, 'movie_id' => 2, 'title' => 'Chapter '.$season, 'season_number' => $season, 'poster' => '/season-'.$season.'.jpg', 'status' => 'Published', 'isPayPerView' => true]);
        }
        foreach ([['ep-1', 'season-1', 1], ['ep-2', 'season-1', 2], ['ep-other', 'season-2', 1]] as [$id, $season, $number]) {
            DB::table('episodes')->insert(['id' => $id, 'season_id' => $season, 'title' => 'Episode '.$number, 'episode_number' => $number, 'status' => 'Published', 'is_active' => true, 'isPayPerView' => true]);
        }
    }

    public function test_ppv_catalog_and_two_column_batches_include_poster_and_exact_scope(): void
    {
        $this->seedSeries();
        $this->getJson('/api/v4/admin/redemption-codes/options', $this->headersFor('admin'))
            ->assertOk()->assertJsonPath('data.series.0.title', 'Demo Series')
            ->assertJsonCount(2, 'data.series.0.seasons')->assertJsonCount(2, 'data.series.0.seasons.0.episodes');
        foreach (['movie' => 'film', 'season' => 'season-1', 'episode' => 'ep-1'] as $type => $id) {
            $body = ['label' => 'PPV cards', 'type' => 'ppv', 'content_type' => $type, 'movie_id' => $id,
                'page_count' => 1, 'batch_id' => (string) \Illuminate\Support\Str::uuid()];
            $response = $this->postJson('/api/v4/admin/redemption-codes/batches', $body, $this->headersFor('admin'))
                ->assertCreated()->assertJsonPath('data.codes_per_page', 8)->assertJsonCount(8, 'data.codes')
                ->assertJsonPath('data.content_type', $type);
            if ($type !== 'movie') {
                $response->assertJsonPath('data.poster_url', '/season-1.jpg')->assertJsonPath('data.series_title', 'Demo Series');
            }
        }
        $this->assertSame(24, RedemptionCode::count());
    }

    public function test_season_grant_only_unlocks_episodes_in_its_actual_season(): void
    {
        $this->seedSeries();
        DB::table('episodes')->where('id', 'ep-2')->update(['isPayPerView' => false, 'isPremium' => true]);
        $code = $this->code('ppv', ['content_type' => 'season', 'movie_id' => 'season-1']);
        $this->getJson('/api/v4/redemptions/'.$code->token, $this->headersFor('alice'))->assertOk()
            ->assertJsonPath('data.content_type', 'season')->assertJsonPath('data.scope_label', 'Season 1 · Full season');
        $this->redeem($code)->assertOk()->assertJsonPath('data.content_type', 'season');
        $this->assertSame('season', PaymentHistory::first()->meta['content_type']);
        $controller = $this->ppvPlaybackController();
        $start = function (string $id, string $season) use ($controller) {
            $request = \Illuminate\Http\Request::create('/', 'POST', ['user_id' => 'alice', 'movie_id' => $id, 'season_id' => $season, 'type' => 'episode', 'platform' => 'ios', 'device_type' => 'tv']);
            $request->headers->set('Device-Token', 'alice-tv');

            return $controller->start($request);
        };
        $this->assertSame(200, $start('ep-1', 'season-1')->getStatusCode());
        $this->assertSame(200, $start('ep-2', 'season-1')->getStatusCode());
        $this->assertSame(403, $start('ep-other', 'season-1')->getStatusCode());
    }

    public function test_episode_grant_does_not_unlock_siblings_or_a_movie_with_the_same_id(): void
    {
        $this->seedSeries();
        DB::table('movie')->insert(['id' => 'ep-1', 'title' => 'Different movie', 'status' => 'Published', 'isEnable' => true, 'isPayPerView' => true]);
        $code = $this->code('ppv', ['content_type' => 'episode', 'movie_id' => 'ep-1']);
        $this->redeem($code)->assertOk();
        $controller = $this->ppvPlaybackController();
        $start = function (string $id, string $type) use ($controller) {
            $request = \Illuminate\Http\Request::create('/', 'POST', ['user_id' => 'alice', 'movie_id' => $id, 'season_id' => 'season-1', 'type' => $type, 'platform' => 'ios', 'device_type' => 'tv']);
            $request->headers->set('Device-Token', 'alice-tv');

            return $controller->start($request);
        };
        $this->assertSame(200, $start('ep-1', 'episode')->getStatusCode());
        $this->assertSame(403, $start('ep-2', 'episode')->getStatusCode());
        $this->assertSame(403, $start('ep-1', 'movie')->getStatusCode());
    }

    public function test_ppv_series_targets_require_published_enabled_parents_and_correct_type(): void
    {
        $this->seedSeries();
        $body = ['label' => 'Episode', 'type' => 'ppv', 'content_type' => 'episode', 'movie_id' => 'ep-1'];
        DB::table('movie')->where('id', 'series')->update(['isEnable' => false]);
        $this->postJson('/api/v4/admin/redemption-codes', $body, $this->headersFor('admin'))->assertUnprocessable();
        DB::table('movie')->where('id', 'series')->update(['isEnable' => true]);
        DB::table('episodes')->where('id', 'ep-1')->update(['status' => 'Draft']);
        $this->postJson('/api/v4/admin/redemption-codes', $body, $this->headersFor('admin'))->assertUnprocessable();
        $this->postJson('/api/v4/admin/redemption-codes', [...$body, 'content_type' => 'movie'], $this->headersFor('admin'))->assertUnprocessable();
        $this->assertSame(0, RedemptionCode::count());
    }

    public function test_old_ppv_batches_reflow_without_reissuing_codes(): void
    {
        $batchId = (string) \Illuminate\Support\Str::uuid();
        DB::table('redemption_print_batches')->insert(['id' => $batchId, 'created_by' => 'admin', 'request_fingerprint' => str_repeat('a', 64), 'page_count' => 1]);
        for ($i = 0; $i < 12; $i++) {
            $this->code('ppv', ['batch_id' => $batchId, 'batch_page_count' => 1]);
        }
        $this->getJson('/api/v4/admin/redemption-codes/batches/'.$batchId, $this->headersFor('admin'))
            ->assertOk()->assertJsonPath('data.codes_per_page', 8)->assertJsonPath('data.page_count', 2)->assertJsonCount(12, 'data.codes');
        $this->assertSame(12, RedemptionCode::count());
    }

    public function test_transaction_rolls_back_entitlement_if_recording_activation_fails(): void
    {
        $code = $this->code('subscription');
        PaymentHistory::creating(function () {
            throw new \RuntimeException('Simulated history failure');
        });
        try {
            $this->redeem($code)->assertStatus(500);
            $this->assertSame(0, Subscription::count());
            $this->assertNull(Devices::find(10)->subscription_id);
            $this->assertSame('available', $code->fresh()->status);
        } finally {
            PaymentHistory::flushEventListeners();
        }
    }
}
