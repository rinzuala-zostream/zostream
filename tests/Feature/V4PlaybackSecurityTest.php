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
use App\Http\Middleware\OwnerDeviceMiddleware;
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

    public function test_inactive_stream_pruning_keeps_active_and_recent_sessions(): void
    {
        $device = $this->device('user-a', 'device-a');
        $active = $this->stream($device, 'active-old-token');
        $active->update(['last_ping' => now()->subDays(3)]);
        $recentStopped = $this->stream($device, 'recent-stopped-token');
        $recentStopped->update([
            'status' => 'stopped',
            'last_ping' => now()->subHours(12),
        ]);
        $oldStopped = $this->stream($device, 'old-stopped-token');
        $oldStopped->update([
            'status' => 'stopped',
            'last_ping' => now()->subHours(25),
        ]);
        $oldExpired = $this->stream($device, 'old-expired-token');
        $oldExpired->update([
            'status' => 'expired',
            'last_ping' => now()->subHours(25),
        ]);

        $this->artisan('streams:prune-inactive', [
            '--hours' => 24,
            '--batch' => 1,
        ])->assertSuccessful();

        $this->assertDatabaseHas('n_active_streams', ['id' => $active->id]);
        $this->assertDatabaseHas('n_active_streams', ['id' => $recentStopped->id]);
        $this->assertDatabaseMissing('n_active_streams', ['id' => $oldStopped->id]);
        $this->assertDatabaseMissing('n_active_streams', ['id' => $oldExpired->id]);
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

    public function test_stop_saves_progress_for_a_previously_inactive_stream(): void
    {
        $device = $this->device('user-a', 'device-a', 'active');
        $stream = $this->stream($device, 'inactive-stream-token');
        $stream->update(['status' => 'expired']);
        $watchPositions = Mockery::mock(WatchPositionController::class);
        $watchPositions->shouldReceive('save')
            ->once()
            ->withArgs(fn (Request $request) => $request->input('position') === 42_000)
            ->andReturn(response()->json(['status' => 'success']));

        $request = Request::create('/api/v4/playback/sessions/stop', 'POST', [
            'auth_user_id' => 'user-a',
            'stream_token' => 'inactive-stream-token',
            'watch_position' => 42_000,
            'content_type' => 'movie',
            'movie_id' => 'movie-1',
            'duration' => 100_000,
        ]);
        $request->headers->set('Device-Token', $device->device_token);

        $response = $this->controller($watchPositions)->stop($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('expired', $stream->fresh()->status);
        $this->assertSame('active', $device->fresh()->status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('playbackDeviceTypes')]
    public function test_owner_is_always_active_and_counts_toward_the_device_limit(string $deviceType, string $platform): void
    {
        DB::table('movie')->insert([
            'id' => 'premium-movie',
            'isPremium' => true,
            'isPayPerView' => false,
        ]);
        $plan = Plan::create([
            'name' => 'One mobile device',
            'device_type' => $deviceType,
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
            'device_type' => $deviceType,
            'is_owner_device' => true,
        ]);
        $otherDevice = $this->device('user-a', 'other-device', 'inactive');
        $otherDevice->update([
            'subscription_id' => $subscription->id,
            'device_type' => $deviceType,
            'is_owner_device' => false,
        ]);

        $request = Request::create('/api/v4/playback/sessions', 'POST', [
            'auth_user_id' => 'user-a',
            'user_id' => 'user-a',
            'subscription_id' => $subscription->id,
            'movie_id' => 'premium-movie',
            'type' => 'movie',
            'device_type' => $deviceType,
            'platform' => $platform,
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

    #[\PHPUnit\Framework\Attributes\DataProvider('renewalDeviceTypes')]
    public function test_manual_renewal_keeps_owner_and_resets_shared_devices(string $deviceType): void
    {
        $plan = Plan::create([
            'name' => 'Browser plan',
            'device_type' => $deviceType,
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
            'device_type' => $deviceType,
            'device_token' => 'owner-browser',
            'is_owner_device' => true,
            'status' => 'inactive',
        ]);
        $activeBrowser = Devices::create([
            'user_id' => 'user-a',
            'device_name' => 'Current browser session',
            'device_type' => $deviceType,
            'device_token' => 'current-browser',
            'is_owner_device' => false,
            'status' => 'active',
        ]);
        $inactiveBrowser = Devices::create([
            'user_id' => 'user-a',
            'device_name' => 'Signed-in browser',
            'device_type' => $deviceType,
            'device_token' => 'inactive-browser',
            'is_owner_device' => false,
            'status' => 'inactive',
        ]);
        $activeStream = $this->stream($activeBrowser, 'shared-browser-stream');

        $request = Request::create('/api/v4/admin/subscriptions/renew', 'POST', [
            'subscription_id' => $subscription->id,
            'user_id' => 'user-a',
            'device_id' => $owner->device_token,
            'device_type' => $deviceType,
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

    public function test_new_session_tokens_are_hashed_at_rest_and_still_authenticate(): void
    {
        $tokens = app(TokenController::class)->generateTokens(
            'user-a',
            'Security test',
            'device-a',
        );

        $record = DB::table('session_tokens')
            ->where('user_id', 'user-a')
            ->first();

        $this->assertNotNull($record);
        $this->assertNotSame($tokens['access_token'], $record->access_token);
        $this->assertNotSame($tokens['refresh_token'], $record->refresh_token);
        $this->assertStringStartsWith('sha256:', $record->access_token);
        $this->assertSame(
            'user-a',
            TokenController::validateToken($tokens['access_token']),
        );
        $this->assertNull(TokenController::validateToken($record->access_token));
    }

    public function test_owner_only_actions_reject_a_shared_device(): void
    {
        Devices::create([
            'user_id' => 'user-a',
            'device_name' => 'Shared mobile',
            'device_type' => 'mobile',
            'device_token' => 'shared-mobile',
            'is_owner_device' => false,
            'status' => 'active',
        ]);
        $request = Request::create('/api/v4/billing/subscriptions', 'POST', [
            'auth_user_id' => 'user-a',
            'auth_device_id' => 'shared-mobile',
        ]);

        $response = app(OwnerDeviceMiddleware::class)->handle(
            $request,
            fn () => response()->json(['status' => 'success']),
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_owner_only_actions_fail_closed_without_device_identity(): void
    {
        $request = Request::create('/api/v4/account', 'PATCH', [
            'auth_user_id' => 'user-a',
        ]);

        $response = app(OwnerDeviceMiddleware::class)->handle(
            $request,
            fn () => response()->json(['status' => 'success']),
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_owner_only_actions_accept_the_verified_owner_device(): void
    {
        $this->device('user-a', 'owner-mobile', 'active');
        $request = Request::create('/api/v4/account', 'PATCH', [
            'auth_user_id' => 'user-a',
            'auth_device_id' => 'owner-mobile',
        ]);

        $response = app(OwnerDeviceMiddleware::class)->handle(
            $request,
            fn () => response()->json(['status' => 'success']),
        );

        $this->assertSame(200, $response->getStatusCode());
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

    public function test_desktop_login_keeps_existing_device_owners_and_shares_one_desktop_category(): void
    {
        Schema::table('n_devices', fn (Blueprint $table) => $table->string('platform')->nullable());
        $user = new UserModel();
        $user->uid = 'user-a';
        $resolver = new class {
            use ResolvesLoginDevices;

            public function login(UserModel $user, string $id, string $type, ?string $platform = null): array
            {
                return $this->resolveLoginDevice($user, null, $id, $id, $type, $platform);
            }
        };

        foreach (['mobile', 'browser', 'tv'] as $type) {
            $this->assertTrue($resolver->login($user, $type.'-owner', $type)['is_owner_device']);
        }
        $legacyDevices = Devices::orderBy('id')->get()->toArray();

        $windows = $resolver->login($user, 'windows-install-id', 'desktop', 'windows');
        $mac = $resolver->login($user, 'mac-install-id', 'desktop', 'macos');
        $this->assertTrue($windows['is_owner_device']);
        $this->assertFalse($mac['is_owner_device']);
        $this->assertSame('desktop', $windows['device']->device_type);
        $this->assertSame('windows', $windows['device']->platform);
        $this->assertSame('macos', $mac['device']->platform);
        $this->assertSame('inactive', $mac['device']->status);

        $mac['device']->update(['status' => 'active']);
        $again = $resolver->login($user, 'mac-install-id', 'desktop');
        $this->assertSame($mac['device']->id, $again['device']->id);
        $this->assertSame('active', $again['device']->status);
        $this->assertSame('macos', $again['device']->platform);
        $this->assertSame($legacyDevices, Devices::whereIn('device_type', ['mobile', 'browser', 'tv'])->orderBy('id')->get()->toArray());
    }

    public function test_desktop_subscription_filter_does_not_return_a_mobile_plan(): void
    {
        foreach (['mobile', 'desktop'] as $type) {
            $plan = Plan::create([
                'name' => ucfirst($type).' plan', 'device_type' => $type,
                'device_limit' => 1, 'price' => 100, 'duration_days' => 30,
            ]);
            Subscription::create([
                'user_id' => 'user-a', 'plan_id' => $plan->id,
                'start_at' => now()->subDay(), 'end_at' => now()->addDay(), 'is_active' => true,
            ]);
        }
        Schema::create('n_plan_features', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
        });
        $subscriptions = new NewSubscriptionController(Mockery::mock(RazorpayController::class), $this->controller());
        $response = $subscriptions->getByUser(Request::create('/', 'GET', ['device_type' => 'desktop']), 'user-a');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('desktop', $response->getData(true)['plan']['device_type']);
        $this->assertSame(2, Subscription::count());
    }

    public function test_desktop_cannot_play_using_an_existing_mobile_subscription(): void
    {
        DB::table('movie')->insert(['id' => 'premium-movie', 'isPremium' => true, 'isPayPerView' => false]);
        $plan = Plan::create([
            'name' => 'Mobile plan', 'device_type' => 'mobile',
            'device_limit' => 1, 'price' => 100, 'duration_days' => 30,
        ]);
        $subscription = Subscription::create([
            'user_id' => 'user-a', 'plan_id' => $plan->id,
            'start_at' => now()->subDay(), 'end_at' => now()->addDay(), 'is_active' => true,
        ]);
        $device = $this->device('user-a', 'desktop-install-id', 'active');
        $device->update(['device_type' => 'desktop']);
        $before = $device->fresh()->toArray();
        $request = Request::create('/', 'POST', [
            'auth_user_id' => 'user-a', 'user_id' => 'user-a',
            'subscription_id' => $subscription->id, 'movie_id' => 'premium-movie',
            'type' => 'movie', 'device_type' => 'desktop', 'platform' => 'windows',
        ]);
        $request->headers->set('Device-Token', $device->device_token);

        $response = $this->controller()->start($request);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame($before, $device->fresh()->toArray());
        $this->assertSame(0, ActiveStream::count());
    }

    public function test_desktop_otp_login_persists_the_os_and_shares_a_desktop_owner(): void
    {
        Schema::table('n_devices', fn (Blueprint $table) => $table->string('platform')->nullable());
        Schema::table('user', function (Blueprint $table) {
            $table->string('device_id')->nullable();
            $table->string('device_name')->nullable();
            $table->timestamps();
        });
        Schema::create('otp_requests', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('otp_code');
            $table->dateTime('expires_at');
        });
        DB::table('user')->insert(['uid' => 'user-a']);
        $tokens = Mockery::mock(TokenController::class);
        $tokens->shouldReceive('generateTokens')->twice()->andReturn([
            'access_token' => 'test-access', 'refresh_token' => 'test-refresh',
        ]);
        $otp = new \App\Http\Controllers\OTPController(
            Mockery::mock(\App\Http\Controllers\WhatsAppController::class),
            $tokens,
            Mockery::mock(\App\Http\Controllers\New\DeviceController::class),
        );
        foreach (['windows', 'macos'] as $platform) {
            DB::table('otp_requests')->insert([
                'user_id' => 'user-a', 'otp_code' => \Illuminate\Support\Facades\Hash::make('765432'),
                'expires_at' => now()->addMinutes(5),
            ]);
            $request = Request::create('/', 'POST', [
                'user_id' => 'user-a', 'otp' => '765432',
                'device_id' => $platform.'-install', 'device_name' => $platform,
                'device_type' => 'desktop',
            ]);
            $request->headers->set('X-Client-Platform', $platform);
            $response = $otp->verify($request);
            $this->assertSame('success', $response->getData(true)['status']);
            $this->assertDatabaseHas('n_devices', [
                'device_token' => $platform.'-install', 'device_type' => 'desktop', 'platform' => $platform,
            ]);
        }
        $this->assertSame(0, DB::table('otp_requests')->count());
        $this->assertSame(1, Devices::where('device_type', 'desktop')->where('is_owner_device', true)->count());
    }

    public function test_desktop_playback_creates_a_desktop_stream_and_saves_progress_on_stop(): void
    {
        DB::table('movie')->insert(['id' => 'free-movie', 'isPremium' => false, 'isPayPerView' => false]);
        $device = $this->device('user-a', 'windows-install', 'active');
        $device->update(['device_type' => 'desktop']);
        $movies = Mockery::mock(MovieController::class);
        $movies->shouldReceive('getLink')->once()->andReturn(response()->json([
            'status' => 'success', 'title' => 'Free movie',
            'links' => ['dash_url' => 'https://media.example.test/movie/manifest.mpd'],
        ]));
        $watch = Mockery::mock(WatchPositionController::class);
        $watch->shouldReceive('getWatchPosition')->once()->andReturn(response()->json([
            'status' => 'success', 'watchPosition' => 15000,
        ]));
        $watch->shouldReceive('save')->once()->withArgs(fn (Request $request) =>
            $request->input('user_id') === 'user-a' && $request->input('position') === 25000
        )->andReturn(response()->json(['status' => 'success']));
        $ads = Mockery::mock(\App\Http\Controllers\Api\V4\AdServingController::class);
        $ads->shouldReceive('serve')->andReturn(response()->json(['success' => true, 'data' => null]));
        $controller = new NewStreamController(Mockery::mock(HlsFolderController::class), $movies, $watch, $ads);
        $request = Request::create('/', 'POST', [
            'auth_user_id' => 'user-a', 'user_id' => 'user-a',
            'movie_id' => 'free-movie', 'type' => 'movie', 'device_type' => 'desktop', 'platform' => 'windows',
        ]);
        $request->headers->set('Device-Token', $device->device_token);
        $response = $controller->start($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', $response->getData(true)['status']);
        $this->assertSame(15000, $response->getData(true)['watch_position']);
        $this->assertDatabaseHas('n_active_streams', ['device_id' => $device->id, 'device_type' => 'desktop', 'status' => 'active']);

        $stop = Request::create('/', 'POST', [
            'auth_user_id' => 'user-a', 'stream_token' => $response->getData(true)['stream_token'],
            'movie_id' => 'free-movie', 'content_type' => 'movie', 'watch_position' => 25000, 'duration' => 100000,
        ]);
        $stop->headers->set('Device-Token', $device->device_token);
        $this->assertSame(200, $controller->stop($stop)->getStatusCode());
        $this->assertDatabaseHas('n_active_streams', ['device_id' => $device->id, 'status' => 'stopped']);
    }

    public function test_qr_scanning_phone_cannot_replace_the_desktop_install_identity(): void
    {
        Schema::table('n_devices', fn (Blueprint $table) => $table->string('platform')->nullable());
        Schema::table('user', function (Blueprint $table) {
            $table->string('device_id')->nullable();
            $table->string('device_name')->nullable();
            $table->timestamps();
        });
        DB::table('user')->insert(['uid' => 'user-a']);
        $class = \App\Http\Controllers\New\QRSessionController::class;
        $controller = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $reference = Mockery::mock();
        $reference->shouldReceive('getValue')->once()->andReturn([
            'type' => 'login', 'device_type' => 'desktop', 'platform' => 'macos',
            'device_id' => 'mac-install-id', 'device_name' => 'My Mac', 'expires_at' => time() + 120,
        ]);
        $reference->shouldReceive('update')->twice();
        $database = Mockery::mock();
        $database->shouldReceive('getReference')->once()->with('qr_sessions/test-token')->andReturn($reference);
        $tokens = Mockery::mock(TokenController::class);
        $tokens->shouldReceive('generateTokens')->once()->with('user-a', 'My Mac', 'mac-install-id')
            ->andReturn(['access_token' => 'test-access', 'refresh_token' => 'test-refresh']);
        (new \ReflectionProperty($class, 'database'))->setValue($controller, $database);
        (new \ReflectionProperty($class, 'tokenController'))->setValue($controller, $tokens);
        $request = Request::create('/', 'POST', [
            'token' => 'test-token', 'user_id' => 'user-a',
            'device_id' => 'scanning-phone-id', 'device_name' => 'My phone', 'device_type' => 'mobile',
        ]);
        $request->headers->set('X-Client-Platform', 'android');
        $response = $controller->verify($request);
        $this->assertSame('success', $response->getData(true)['status']);
        $this->assertDatabaseHas('n_devices', [
            'device_token' => 'mac-install-id', 'device_name' => 'My Mac',
            'device_type' => 'desktop', 'platform' => 'macos',
        ]);
        $this->assertSame(0, Devices::where('device_token', 'scanning-phone-id')->count());
    }

    public static function playbackDeviceTypes(): array
    {
        return [['mobile', 'android'], ['browser', 'web'], ['tv', 'android-tv'], ['desktop', 'windows'], ['desktop', 'macos']];
    }

    public static function renewalDeviceTypes(): array
    {
        return [['mobile'], ['browser'], ['tv'], ['desktop']];
    }

    private function controller(?WatchPositionController $watchPositions = null): NewStreamController
    {
        return new NewStreamController(
            Mockery::mock(HlsFolderController::class),
            Mockery::mock(MovieController::class),
            $watchPositions ?? Mockery::mock(WatchPositionController::class),
            Mockery::mock(\App\Http\Controllers\Api\V4\AdServingController::class),
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
