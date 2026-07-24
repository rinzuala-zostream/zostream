<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V4\AccountController;
use App\Http\Controllers\Api\V4\OfflineController;
use App\Http\Controllers\Api\V4\PlaybackController;
use App\Http\Controllers\Api\V4\QrSessionController;
use App\Http\Controllers\Api\V4\SupportController;
use App\Http\Controllers\New\CustomerSupportController;
use App\Http\Controllers\New\DeviceController;
use App\Http\Controllers\New\OfflineController as LegacyOfflineController;
use App\Http\Controllers\New\QRSessionController as LegacyQrSessionController;
use App\Http\Controllers\New\SubscriptionController;
use App\Http\Controllers\New\UserController;
use App\Http\Controllers\New\WhatsAppPhoneController;
use App\Http\Controllers\NewStreamController;
use App\Http\Controllers\OTPController;
use App\Http\Middleware\V4ResponseEnvelope;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class V4MobileAdapterTest extends TestCase
{
    public function test_playback_rejects_a_device_token_that_does_not_match_the_access_token(): void
    {
        $streams = Mockery::mock(NewStreamController::class);
        $streams->shouldNotReceive('start');

        $request = Request::create('/api/v4/playback/sessions', 'POST', [
            'auth_user_id' => 'trusted-user',
            'auth_device_id' => 'trusted-device',
            'user_id' => 'attacker-user',
        ]);
        $request->headers->set('Device-Token', 'different-device');

        $response = (new PlaybackController($streams))->start($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_playback_adapter_forces_the_authenticated_user(): void
    {
        $streams = Mockery::mock(NewStreamController::class);
        $streams->shouldReceive('ping')
            ->once()
            ->withArgs(fn (Request $request) => $request->input('user_id') === 'trusted-user')
            ->andReturn(response()->json(['status' => 'success']));

        $request = Request::create('/api/v4/playback/sessions/heartbeat', 'POST', [
            'auth_user_id' => 'trusted-user',
            'auth_device_id' => 'trusted-device',
            'user_id' => 'attacker-user',
        ]);
        $request->headers->set('Device-Token', 'trusted-device');

        (new PlaybackController($streams))->heartbeat($request);
    }

    public function test_customer_support_list_is_scoped_to_the_authenticated_user(): void
    {
        $support = Mockery::mock(CustomerSupportController::class);
        $support->shouldReceive('index')
            ->once()
            ->withArgs(fn (Request $request) => (
                $request->input('user_id') === 'trusted-user'
                && $request->query('user_id') === 'trusted-user'
            ))
            ->andReturn(response()->json(['status' => 'success']));

        $request = Request::create('/api/v4/support/tickets', 'GET', [
            'auth_user_id' => 'trusted-user',
            'user_id' => 'attacker-user',
        ]);

        (new SupportController($support))->index($request);
    }

    public function test_customer_cannot_impersonate_an_admin_support_reply(): void
    {
        $response = (new SupportController(Mockery::mock(CustomerSupportController::class)))
            ->reply();

        $this->assertSame(405, $response->getStatusCode());
    }

    public function test_account_phone_uses_the_authenticated_user_not_client_input(): void
    {
        $phones = Mockery::mock(WhatsAppPhoneController::class);
        $phones->shouldReceive('checkPhone')
            ->once()
            ->withArgs(fn (Request $request) => $request->input('user_id') === 'trusted-user')
            ->andReturn(response()->json(['status' => true]));

        $controller = new AccountController(
            Mockery::mock(UserController::class),
            Mockery::mock(DeviceController::class),
            Mockery::mock(SubscriptionController::class),
            Mockery::mock(OTPController::class),
            $phones,
        );
        $request = Request::create('/api/v4/account/phone/status', 'GET', [
            'auth_user_id' => 'trusted-user',
            'user_id' => 'attacker-user',
        ]);

        $controller->phoneStatus($request);
    }

    public function test_offline_access_uses_the_authenticated_user_not_client_input(): void
    {
        $legacy = Mockery::mock(LegacyOfflineController::class);
        $legacy->shouldReceive('requestOffline')
            ->once()
            ->withArgs(fn (Request $request) => $request->input('user_id') === 'trusted-user')
            ->andReturn(response()->json(['status' => 'success']));

        $request = Request::create('/api/v4/offline/access', 'GET', [
            'auth_user_id' => 'trusted-user',
            'user_id' => 'attacker-user',
        ]);

        (new OfflineController($legacy))->requestAccess($request);
    }

    public function test_qr_selection_uses_the_authenticated_user_not_client_input(): void
    {
        $legacy = Mockery::mock(LegacyQrSessionController::class);
        $legacy->shouldReceive('updateSelection')
            ->once()
            ->withArgs(fn (Request $request, string $token) => (
                $request->input('user_id') === 'trusted-user'
                && $token === '1234567890123456789012'
            ))
            ->andReturn(response()->json(['status' => 'success']));

        $request = Request::create('/api/v4/qr-sessions/token/selection', 'POST', [
            'auth_user_id' => 'trusted-user',
            'user_id' => 'attacker-user',
        ]);

        (new QrSessionController($legacy))
            ->updateSelection($request, '1234567890123456789012');
    }

    public function test_qr_create_returns_a_v4_polling_url(): void
    {
        $legacy = Mockery::mock(LegacyQrSessionController::class);
        $legacy->shouldReceive('create')
            ->once()
            ->andReturn(response()->json([
                'status' => 'success',
                'token' => '1234567890123456789012',
                'qr_url' => 'https://example.test/api/v3.0/qr/status/old',
            ]));

        $response = (new QrSessionController($legacy))
            ->create(Request::create('/api/v4/qr-sessions', 'POST'));

        $this->assertStringEndsWith(
            '/api/v4/qr-sessions/1234567890123456789012/status',
            $response->getData(true)['qr_url']
        );
    }

    public function test_no_subscriptions_is_a_successful_empty_mobile_collection(): void
    {
        $subscriptions = Mockery::mock(SubscriptionController::class);
        $subscriptions->shouldReceive('getByUser')
            ->once()
            ->withArgs(fn (Request $request, string $userId) => $userId === 'trusted-user')
            ->andReturn(response()->json([
                'status' => 'error',
                'message' => 'No subscriptions found for this user',
            ], 404));

        $controller = new AccountController(
            Mockery::mock(UserController::class),
            Mockery::mock(DeviceController::class),
            $subscriptions,
            Mockery::mock(OTPController::class),
            Mockery::mock(WhatsAppPhoneController::class),
        );
        $request = Request::create('/api/v4/account/subscriptions', 'GET', [
            'auth_user_id' => 'trusted-user',
        ]);
        $response = $controller->subscriptions($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['status']);
        $this->assertSame([], $response->getData(true)['data']['data']);
    }

    public function test_legacy_payload_that_mentions_success_and_error_is_still_wrapped(): void
    {
        $request = Request::create('/api/v4/test', 'GET');
        $request->attributes->set('request_id', 'mobile-contract-test');
        $request->attributes->set('client_context', ['platform' => 'ios']);

        $response = (new V4ResponseEnvelope)->handle(
            $request,
            fn () => response()->json([
                'success' => true,
                'error' => null,
                'status' => 'success',
            ])
        );
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertSame('success', $payload['data']['status']);
        $this->assertSame('mobile-contract-test', $payload['meta']['request_id']);
    }
}
