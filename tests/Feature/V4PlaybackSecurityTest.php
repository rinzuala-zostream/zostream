<?php

namespace Tests\Feature;

use App\Http\Controllers\Concerns\ResolvesLoginDevices;
use App\Http\Controllers\HlsFolderController;
use App\Http\Controllers\New\MovieController;
use App\Http\Controllers\New\SubscriptionController as NewSubscriptionController;
use App\Http\Controllers\NewStreamController;
use App\Http\Controllers\RazorpayController;
use App\Http\Controllers\TokenController;
use App\Http\Controllers\WatchPositionController;
use App\Models\New\ActiveStream;
use App\Models\New\Devices;
use App\Models\New\Plan;
use App\Models\New\Subscription;
use App\Models\UserModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class V4PlaybackSecurityTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = (string) config('database.default');
        config([
            'database.default' => 'playback_testing',
            'database.connections.playback_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('playback_testing');
        DB::reconnect('playback_testing');

        Schema::create('movie', function (Blueprint $table) {
            $table->increments('num');
            $table->string('id')->unique();
            $table->boolean('isPremium')->default(false);
            $table->boolean('isPayPerView')->default(false);
        });
        Schema::create('user', function (Blueprint $table) {
            $table->increments('num');
            $table->string('uid')->unique();
            $table->string('auth_phone')->nullable();
        });
        Schema::create('n_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->dateTime('start_at')->nullable();
            $table->dateTime('end_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('renewed_by')->nullable();
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
        Schema::create('n_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('user_id');
            $table->string('device_name')->nullable();
            $table->string('device_type');
            $table->string('device_token')->unique();
            $table->boolean('is_owner_device')->default(false);
            $table->dateTime('last_activity')->nullable();
            $table->string('status')->default('inactive');
            $table->timestamps();
        });
        Schema::create('n_active_streams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('device_id');
            $table->string('device_type');
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('content_id')->nullable();
            $table->string('content_key')->nullable();
            $table->string('stream_token');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('last_ping')->nullable();
            $table->dateTime('viewed_at')->nullable();
            $table->string('status')->default('active');
        });
        Schema::create('session_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('access_token')->nullable();
            $table->string('refresh_token')->nullable();
            $table->dateTime('access_expires_at')->nullable();
            $table->string('device_id')->nullable();
            $table->dateTime('refresh_expires_at')->nullable();
            $table->string('device_name')->nullable();
            $table->timestamps();
        });
        Schema::create('n_stream_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('event_type');
            $table->json('event_data')->nullable();
            $table->timestamps();
        });
        Schema::create('n_payment_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('user_id');
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('movie_id')->nullable();
            $table->string('device_type')->nullable();
            $table->string('app_payment_type')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_gateway')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('status')->nullable();
            $table->string('payment_type')->nullable();
            $table->dateTime('payment_date')->nullable();
            $table->dateTime('expiry_date')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('playback_testing');
        config(['database.default' => $this->originalConnection]);

        parent::tearDown();
    }

    public function test_heartbeat_cannot_keep_another_devices_stream_alive(): void
    {
        $ownDevice = $this->device('user-a', 'device-a');
        $otherDevice = $this->device('user-b', 'device-b');
        $otherStream = $this->stream($otherDevice, 'other-stream-token');
        $lastPing = $otherStream->last_ping->toDateTimeString();

        $request = Request::create('/api/v4/playback/sessions/heartbeat', 'POST', [
            'auth_user_id' => 'user-a',
            'stream_token' => 'other-stream-token',
            'movie_id' => 'movie-1',
            'type' => 'movie',
        ]);
        $request->headers->set('Device-Token', $ownDevice->device_token);

        $response = $this->controller()->ping($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame($lastPing, $otherStream->fresh()->last_ping->toDateTimeString());
        $this->assertSame('active', $otherStream->fresh()->status);
    }

    public function test_heartbeat_does_not_reactivate_a_stopped_session(): void
    {
        $device = $this->device('user-a', 'device-a');
        $stream = $this->stream($device, 'stopped-stream-token');
        $stream->update(['status' => 'stopped']);

        $request = Request::create('/api/v4/playback/sessions/heartbeat', 'POST', [
            'auth_user_id' => 'user-a',
            'stream_token' => 'stopped-stream-token',
            'movie_id' => 'movie-1',
            'type' => 'movie',
        ]);
        $request->headers->set('Device-Token', $device->device_token);

        $response = $this->controller()->ping($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('stopped', $stream->fresh()->status);
    }

    public function test_expired_heartbeat_keeps_the_active_device_entitlement(): void
    {
        $device = $this->device('user-a', 'device-a', 'active');
        $stream = $this->stream($device, 'expired-stream-token');
        $stream->update(['last_ping' => now()->subMinutes(10)]);

        $request = Request::create('/api/v4/playback/sessions/heartbeat', 'POST', [
            'auth_user_id' => 'user-a',
            'stream_token' => 'expired-stream-token',
            'movie_id' => 'movie-1',
            'type' => 'movie',
        ]);
        $request->headers->set('Device-Token', $device->device_token);

        $response = $this->controller()->ping($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('expired', $stream->fresh()->status);
        $this->assertSame('active', $device->fresh()->status);
    }

    public function test_stop_cannot_end_another_devices_stream(): void
    {
        $ownDevice = $this->device('user-a', 'device-a');
        $otherDevice = $this->device('user-b', 'device-b');
        $otherStream = $this->stream($otherDevice, 'other-stream-token');

        $request = Request::create('/api/v4/playback/sessions/stop', 'POST', [
            'auth_user_id' => 'user-a',
            'stream_token' => 'other-stream-token',
            'watch_position' => 10_000,
            'content_type' => 'movie',
            'movie_id' => 'movie-1',
            'duration' => 100_000,
        ]);
        $request->headers->set('Device-Token', $ownDevice->device_token);

        $response = $this->controller()->stop($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('active', $otherStream->fresh()->status);
    }

    public function test_stop_keeps_the_authenticated_device_active(): void
    {
        $device = $this->device('user-a', 'device-a', 'active');
        $stream = $this->stream($device, 'own-stream-token');
        $watchPositions = Mockery::mock(WatchPositionController::class);
        $watchPositions->shouldReceive('save')
            ->once()
            ->withArgs(fn (Request $request) => $request->input('user_id') === 'user-a')
            ->andReturn(response()->json(['status' => 'success']));

        $request = Request::create('/api/v4/playback/sessions/stop', 'POST', [
            'auth_user_id' => 'user-a',
            'user_id' => 'attacker-user',
            'stream_token' => 'own-stream-token',
            'watch_position' => 10_000,
            'content_type' => 'movie',
            'movie_id' => 'movie-1',
            'duration' => 100_000,
        ]);
        $request->headers->set('Device-Token', $device->device_token);

        $response = $this->controller($watchPositions)->stop($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('stopped', $stream->fresh()->status);
        $this->assertSame('active', $device->fresh()->status);
    }

    public function test_owner_is_always_active_and_counts_toward_the_device_limit(): void
    {
        DB::table('movie')->insert([
            'id' => 'premium-movie',
            'isPremium' => true,
            'isPayPerView' => false,
        ]);
        $plan = Plan::create([
            'name' => 'One mobile device',
            'device_type' => 'mobile',
            'device_limit' => 1,
            'price' => 100,
            'duration_days' => 30,
            'quality' => 'FULL_HD',
            'is_active' => true,
        ]);
        $subscription = Subscription::create([
            'user_id' => 'user-a',
            'plan_id' => $plan->id,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $owner = $this->device('user-a', 'owner-device', 'inactive');
        $owner->update([
            'subscription_id' => $subscription->id,
            'is_owner_device' => true,
        ]);
        $otherDevice = $this->device('user-a', 'other-device', 'inactive');
        $otherDevice->update([
            'subscription_id' => $subscription->id,
            'is_owner_device' => false,
        ]);

        $request = Request::create('/api/v4/playback/sessions', 'POST', [
            'auth_user_id' => 'user-a',
            'user_id' => 'user-a',
            'subscription_id' => $subscription->id,
            'movie_id' => 'premium-movie',
            'type' => 'movie',
            'device_type' => 'mobile',
            'platform' => 'android',
        ]);
        $request->headers->set('Device-Token', $otherDevice->device_token);

        $response = $this->controller()->start($request);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('active', $owner->fresh()->status);
        $this->assertSame('inactive', $otherDevice->fresh()->status);
    }

    public function test_start_rejects_a_subscription_owned_by_another_user(): void
    {
        DB::table('movie')->insert([
            'id' => 'premium-movie',
            'isPremium' => true,
            'isPayPerView' => false,
        ]);
        $subscription = Subscription::create([
            'user_id' => 'victim-user',
            'plan_id' => 1,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $device = $this->device('attacker-user', 'attacker-device');

        $request = Request::create('/api/v4/playback/sessions', 'POST', [
            'auth_user_id' => 'attacker-user',
            'user_id' => 'attacker-user',
            'subscription_id' => $subscription->id,
            'movie_id' => 'premium-movie',
            'type' => 'movie',
            'device_type' => 'mobile',
            'platform' => 'android',
        ]);
        $request->headers->set('Device-Token', $device->device_token);

        $response = $this->controller()->start($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($device->fresh()->subscription_id);
    }

    public function test_manual_renewal_keeps_owner_and_resets_shared_browser_devices(): void
    {
        $plan = Plan::create([
            'name' => 'Browser plan',
            'device_type' => 'browser',
            'device_limit' => 2,
            'price' => 100,
            'duration_days' => 30,
            'quality' => 'FULL_HD',
            'is_active' => true,
        ]);
        $subscription = Subscription::create([
            'user_id' => 'user-a',
            'plan_id' => $plan->id,
            'start_at' => now(),
            'end_at' => now()->addMonth(),
            'is_active' => true,
        ]);
        $owner = Devices::create([
            'user_id' => 'user-a',
            'device_name' => 'Owner browser',
            'device_type' => 'browser',
            'device_token' => 'owner-browser',
            'is_owner_device' => true,
            'status' => 'inactive',
        ]);
        $activeBrowser = Devices::create([
            'user_id' => 'user-a',
            'device_name' => 'Current browser session',
            'device_type' => 'browser',
            'device_token' => 'current-browser',
            'is_owner_device' => false,
            'status' => 'active',
        ]);
        $inactiveBrowser = Devices::create([
            'user_id' => 'user-a',
            'device_name' => 'Signed-in browser',
            'device_type' => 'browser',
            'device_token' => 'inactive-browser',
            'is_owner_device' => false,
            'status' => 'inactive',
        ]);
        $activeStream = $this->stream($activeBrowser, 'shared-browser-stream');

        $request = Request::create('/api/v4/admin/subscriptions/renew', 'POST', [
            'subscription_id' => $subscription->id,
            'user_id' => 'user-a',
            'device_id' => $owner->device_token,
            'device_type' => 'browser',
        ]);

        $response = $this->controller()->renew($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('active', $owner->fresh()->status);
        $this->assertSame($subscription->id, $owner->fresh()->subscription_id);
        $this->assertSame('stopped', $activeStream->fresh()->status);
        $this->assertDatabaseMissing('n_devices', ['id' => $activeBrowser->id]);
        $this->assertDatabaseMissing('n_devices', ['id' => $inactiveBrowser->id]);
    }

    public function test_login_does_not_reset_an_active_non_owner_browser(): void
    {
        Devices::create([
            'user_id' => 'user-a',
            'device_name' => 'Owner browser',
            'device_type' => 'browser',
            'device_token' => 'owner-browser',
            'is_owner_device' => true,
            'status' => 'active',
        ]);
        $activeBrowser = Devices::create([
            'user_id' => 'user-a',
            'device_name' => 'Current browser',
            'device_type' => 'browser',
            'device_token' => 'current-browser',
            'is_owner_device' => false,
            'status' => 'active',
        ]);
        $user = new UserModel();
        $user->uid = 'user-a';
        $resolver = new class {
            use ResolvesLoginDevices;

            public function resolve(UserModel $user): array
            {
                return $this->resolveLoginDevice(
                    $user,
                    null,
                    'current-browser',
                    'Current browser',
                    'browser',
                );
            }
        };

        $result = $resolver->resolve($user);

        $this->assertFalse($result['is_owner_device']);
        $this->assertSame('active', $activeBrowser->fresh()->status);
    }

    public function test_logout_stops_playback_without_releasing_device_entitlement(): void
    {
        $device = $this->device('user-a', 'device-a', 'active');
        $stream = $this->stream($device, 'logout-stream-token');
        DB::table('session_tokens')->insert([
            'user_id' => 'user-a',
            'access_token' => 'logout-access-token',
            'refresh_token' => 'logout-refresh-token',
            'access_expires_at' => now()->addHour(),
            'refresh_expires_at' => now()->addMonth(),
            'device_id' => $device->device_token,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $request = Request::create('/api/v4/auth/logout', 'POST', [
            'access_token' => 'logout-access-token',
            'user_id' => 'user-a',
        ]);

        $response = app(TokenController::class)->revoke($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('stopped', $stream->fresh()->status);
        $this->assertSame('active', $device->fresh()->status);
        $this->assertDatabaseMissing('session_tokens', [
            'access_token' => 'logout-access-token',
        ]);
    }

    public function test_pending_manual_subscription_does_not_reset_shared_devices(): void
    {
        DB::table('user')->insert([
            'uid' => 'user-a',
            'auth_phone' => '9999999999',
        ]);
        $plan = Plan::create([
            'name' => 'Browser plan',
            'device_type' => 'browser',
            'device_limit' => 2,
            'price' => 100,
            'duration_days' => 30,
            'quality' => 'FULL_HD',
            'is_active' => true,
        ]);
        $owner = Devices::create([
            'user_id' => 'user-a',
            'device_name' => 'Owner browser',
            'device_type' => 'browser',
            'device_token' => 'owner-browser',
            'is_owner_device' => true,
            'status' => 'active',
        ]);
        $sharedBrowser = Devices::create([
            'user_id' => 'user-a',
            'device_name' => 'Shared browser',
            'device_type' => 'browser',
            'device_token' => 'shared-browser',
            'is_owner_device' => false,
            'status' => 'active',
        ]);
        $streams = Mockery::mock(NewStreamController::class);
        $streams->shouldNotReceive('renew');
        $controller = new NewSubscriptionController(
            Mockery::mock(RazorpayController::class),
            $streams,
        );
        $request = Request::create('/api/v4/admin/subscriptions/with-payment', 'POST', [
            'user_id' => 'user-a',
            'plan_id' => $plan->id,
            'status' => 'pending',
            'payment_type' => 'new',
            'payment_method' => 'manual',
            'payment_gateway' => 'manual',
            'transaction_id' => 'pending-manual-1',
        ]);

        $response = $controller->createSubscriptionWithPayment($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertDatabaseHas('n_devices', ['id' => $owner->id]);
        $this->assertDatabaseHas('n_devices', ['id' => $sharedBrowser->id]);
        $this->assertDatabaseHas('n_payment_histories', [
            'transaction_id' => 'pending-manual-1',
            'status' => 'pending',
        ]);
    }

    public function test_successful_manual_subscription_rolls_back_when_device_reset_fails(): void
    {
        DB::table('user')->insert([
            'uid' => 'user-a',
            'auth_phone' => '9999999999',
        ]);
        $plan = Plan::create([
            'name' => 'Browser plan',
            'device_type' => 'browser',
            'device_limit' => 2,
            'price' => 100,
            'duration_days' => 30,
            'quality' => 'FULL_HD',
            'is_active' => true,
        ]);
        $owner = Devices::create([
            'user_id' => 'user-a',
            'device_name' => 'Owner browser',
            'device_type' => 'browser',
            'device_token' => 'owner-browser',
            'is_owner_device' => true,
            'status' => 'active',
        ]);
        $streams = Mockery::mock(NewStreamController::class);
        $streams->shouldReceive('renew')
            ->once()
            ->andReturn(response()->json([
                'status' => 'error',
                'message' => 'Device reset rejected',
            ], 403));
        $controller = new NewSubscriptionController(
            Mockery::mock(RazorpayController::class),
            $streams,
        );
        $request = Request::create('/api/v4/admin/subscriptions/with-payment', 'POST', [
            'user_id' => 'user-a',
            'plan_id' => $plan->id,
            'status' => 'success',
            'payment_type' => 'new',
            'payment_method' => 'manual',
            'payment_gateway' => 'manual',
            'transaction_id' => 'failed-reset-1',
        ]);

        $response = $controller->createSubscriptionWithPayment($request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertDatabaseMissing('n_subscriptions', ['user_id' => 'user-a']);
        $this->assertDatabaseMissing('n_payment_histories', [
            'transaction_id' => 'failed-reset-1',
        ]);
        $this->assertNull($owner->fresh()->subscription_id);
    }

    private function controller(?WatchPositionController $watchPositions = null): NewStreamController
    {
        return new NewStreamController(
            Mockery::mock(HlsFolderController::class),
            Mockery::mock(MovieController::class),
            $watchPositions ?? Mockery::mock(WatchPositionController::class),
        );
    }

    private function device(string $userId, string $token, string $status = 'inactive'): Devices
    {
        return Devices::create([
            'user_id' => $userId,
            'device_name' => $token,
            'device_type' => 'mobile',
            'device_token' => $token,
            'is_owner_device' => true,
            'status' => $status,
        ]);
    }

    private function stream(Devices $device, string $token): ActiveStream
    {
        return ActiveStream::create([
            'device_id' => $device->id,
            'device_type' => $device->device_type,
            'content_type' => 'movie',
            'content_key' => 'movie-1',
            'stream_token' => $token,
            'started_at' => now(),
            'last_ping' => now()->subMinute(),
            'status' => 'active',
        ]);
    }
}
