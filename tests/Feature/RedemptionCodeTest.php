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
        Schema::create('n_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('device_type');
            $t->integer('duration_days');
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
            $t->string('status');
            $t->boolean('isEnable');
            $t->boolean('isPayPerView');
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

    public function test_ppv_grants_play_only_on_the_selected_device_and_keep_existing_owner_rules(): void
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
        $controller = new \App\Http\Controllers\NewStreamController($hls, $movies, $watch, $ads);
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
