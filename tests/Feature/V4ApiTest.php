<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class V4ApiTest extends TestCase
{
    public function test_otp_entry_points_use_separate_named_rate_limiters(): void
    {
        $expectedMiddleware = [
            'api/v4/auth/otp/request' => 'throttle:otp-request',
            'api/v4/auth/admin/otp/request' => 'throttle:admin-otp-request',
            'api/v4/auth/otp/verify' => 'throttle:otp-verify',
            'api/v4/account-deletion/otp' => 'throttle:account-deletion-otp',
        ];

        foreach ($expectedMiddleware as $uri => $middleware) {
            $route = collect(Route::getRoutes()->getRoutes())->first(
                fn ($route) => $route->uri() === $uri
            );

            $this->assertNotNull($route, $uri);
            $this->assertContains($middleware, $route->gatherMiddleware(), $uri);
        }
    }

    public function test_admin_otp_rate_limit_is_keyed_by_normalized_recipient(): void
    {
        $limiter = RateLimiter::limiter('admin-otp-request');

        $this->assertNotNull($limiter);

        $localPhone = Request::create('/api/v4/auth/admin/otp/request', 'POST', [
            'country_code' => '+91',
            'phone_number' => '88370 76347',
        ]);
        $fullPhone = Request::create('/api/v4/auth/admin/otp/request', 'POST', [
            'country_code' => '91',
            'phone_number' => '+91 88370 76347',
        ]);
        $otherPhone = Request::create('/api/v4/auth/admin/otp/request', 'POST', [
            'country_code' => '+91',
            'phone_number' => '99999 99999',
        ]);

        $localLimit = $limiter($localPhone);
        $fullLimit = $limiter($fullPhone);
        $otherLimit = $limiter($otherPhone);

        $this->assertSame(6, $localLimit->maxAttempts);
        $this->assertSame(60, $localLimit->decaySeconds);
        $this->assertSame($localLimit->key, $fullLimit->key);
        $this->assertNotSame($localLimit->key, $otherLimit->key);
    }

    public function test_mobile_otp_rate_limit_does_not_collide_for_users_on_the_same_ip(): void
    {
        $limiter = RateLimiter::limiter('otp-request');

        $this->assertNotNull($limiter);

        $firstUser = Request::create('/api/v4/auth/otp/request', 'POST', [
            'country_code' => '+91',
            'phone_number' => '88370 76347',
            'device_type' => 'mobile',
        ], [], [], ['REMOTE_ADDR' => '10.0.0.5']);
        $secondUser = Request::create('/api/v4/auth/otp/request', 'POST', [
            'country_code' => '+91',
            'phone_number' => '99999 99999',
            'device_type' => 'mobile',
        ], [], [], ['REMOTE_ADDR' => '10.0.0.5']);

        $firstLimit = $limiter($firstUser);
        $secondLimit = $limiter($secondUser);

        $this->assertSame(10, $firstLimit->maxAttempts);
        $this->assertSame(60, $firstLimit->decaySeconds);
        $this->assertNotSame($firstLimit->key, $secondLimit->key);
    }

    public function test_playback_routes_use_separate_named_rate_limiters(): void
    {
        $expectedMiddleware = [
            'api/v4/playback/sessions' => 'throttle:playback-start',
            'api/v4/playback/sessions/heartbeat' => 'throttle:playback-heartbeat',
            'api/v4/playback/sessions/stop' => 'throttle:playback-stop',
        ];

        foreach ($expectedMiddleware as $uri => $middleware) {
            $route = collect(Route::getRoutes()->getRoutes())->first(
                fn ($route) => $route->uri() === $uri
            );

            $this->assertNotNull($route, $uri);
            $this->assertContains($middleware, $route->gatherMiddleware(), $uri);
        }
    }

    public function test_playback_rate_limit_does_not_collide_for_devices_on_the_same_ip(): void
    {
        $limiter = RateLimiter::limiter('playback-heartbeat');

        $this->assertNotNull($limiter);

        $firstDevice = Request::create('/api/v4/playback/sessions/heartbeat', 'POST', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.5',
        ]);
        $firstDevice->merge(['auth_device_id' => 'device-one']);

        $secondDevice = Request::create('/api/v4/playback/sessions/heartbeat', 'POST', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.5',
        ]);
        $secondDevice->merge(['auth_device_id' => 'device-two']);

        $firstLimit = $limiter($firstDevice);
        $secondLimit = $limiter($secondDevice);

        $this->assertSame(300, $firstLimit->maxAttempts);
        $this->assertSame(60, $firstLimit->decaySeconds);
        $this->assertNotSame($firstLimit->key, $secondLimit->key);
    }

    public function test_health_response_uses_the_canonical_envelope(): void
    {
        $response = $this->withHeaders([
            'X-Client-Platform' => 'web',
            'X-Client-Version' => 'test',
            'X-Request-ID' => 'test-request-1234',
        ])->getJson('/api/v4/system/health');

        $response
            ->assertOk()
            ->assertHeader('X-API-Version', '4')
            ->assertHeader('X-Request-ID', 'test-request-1234')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.service', 'zostream-api')
            ->assertJsonPath('meta.request_id', 'test-request-1234')
            ->assertJsonPath('meta.client.platform', 'web')
            ->assertJsonPath('error', null);
    }

    public function test_protected_routes_return_a_canonical_authentication_error(): void
    {
        $response = $this->getJson('/api/v4/account');

        $response
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonStructure([
                'meta' => ['request_id', 'api_version', 'client'],
            ]);
    }

    public function test_every_admin_route_requires_customer_and_admin_authentication(): void
    {
        $adminRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v4/admin/'))
            // A browser creates this high-entropy, short-lived session before
            // it has an access token. Every other admin route stays protected.
            ->reject(fn ($route) => $route->uri() === 'api/v4/admin/qr-sessions');

        $this->assertNotEmpty($adminRoutes);

        foreach ($adminRoutes as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth.token', $middleware, $route->uri());
            $this->assertContains('admin.token', $middleware, $route->uri());
        }
    }

    public function test_admin_banner_record_can_be_loaded_for_editing(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => $route->uri() === 'api/v4/admin/banners/{id}'
                && in_array('GET', $route->methods(), true)
        );

        $this->assertNotNull($route);
        $this->assertSame(
            'App\\Http\\Controllers\\New\\BannerController@show',
            $route->getActionName()
        );
        $this->assertContains('auth.token', $route->gatherMiddleware());
        $this->assertContains('admin.token', $route->gatherMiddleware());
    }

    public function test_admin_movie_search_is_available_to_relationship_pickers(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => $route->uri() === 'api/v4/admin/catalog/items/search'
                && in_array('GET', $route->methods(), true)
        );

        $this->assertNotNull($route);
        $this->assertSame(
            'App\\Http\\Controllers\\New\\MovieController@searchForAdmin',
            $route->getActionName()
        );
        $this->assertContains('auth.token', $route->gatherMiddleware());
        $this->assertContains('admin.token', $route->gatherMiddleware());
    }

    public function test_v4_mutations_are_authenticated_except_for_explicit_entry_points(): void
    {
        $publicMutations = [
            'api/v4/auth/otp/request',
            'api/v4/auth/admin/otp/request',
            'api/v4/auth/otp/verify',
            'api/v4/auth/tokens/refresh',
            'api/v4/account-deletion/otp',
            'api/v4/account-deletion',
            'api/v4/qr-sessions',
            'api/v4/admin/qr-sessions',
            'api/v4/external/subscription-history',
            'api/v4/webhooks/razorpay',
            'api/v4/webhooks/whatsapp',
        ];

        $mutationRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v4/'))
            ->filter(fn ($route) => count(array_intersect(
                $route->methods(),
                ['POST', 'PUT', 'PATCH', 'DELETE']
            )) > 0);

        foreach ($mutationRoutes as $route) {
            if (in_array($route->uri(), $publicMutations, true)) {
                continue;
            }

            $this->assertContains('auth.token', $route->gatherMiddleware(), $route->uri());
        }
    }

    public function test_public_account_deletion_requires_a_valid_challenge_and_confirmation(): void
    {
        $headers = [
            'X-Client-Platform' => 'web',
            'X-Client-Version' => 'test',
        ];

        $this->withHeaders($headers)
            ->postJson('/api/v4/account-deletion/otp')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->withHeaders($headers)
            ->deleteJson('/api/v4/account-deletion', [
                'deletion_token' => 'not-a-valid-encrypted-challenge',
                'otp' => '326416',
                'confirmed' => true,
            ])
            ->assertBadRequest()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This account deletion request is invalid. Please request a new code.');
    }

    public function test_sensitive_legacy_notification_and_reel_mutations_are_authenticated(): void
    {
        $protectedUris = [
            'api/send-fcm',
            'api/v3.0/reels',
            'api/v3.0/reels/{id}/comments',
            'api/v3.0/reels/{id}/like',
            'api/v3.0/reels/{id}/watch',
            'api/v3.0/reels/generate-feed',
        ];

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array($route->uri(), $protectedUris, true))
            ->filter(fn ($route) => count(array_intersect(
                $route->methods(),
                ['POST', 'PUT', 'PATCH', 'DELETE']
            )) > 0);

        $this->assertEqualsCanonicalizing(
            $protectedUris,
            $routes->map(fn ($route) => $route->uri())->unique()->values()->all(),
        );

        foreach ($routes as $route) {
            $this->assertContains('auth.token', $route->gatherMiddleware(), $route->uri());
        }

        $notificationRoute = $routes->first(
            fn ($route) => $route->uri() === 'api/send-fcm'
        );
        $this->assertNotNull($notificationRoute);
        $this->assertContains('admin.token', $notificationRoute->gatherMiddleware());
    }

    public function test_razorpay_webhook_rejects_an_invalid_signature(): void
    {
        config(['razorpay.webhook_secret' => 'test-webhook-secret']);

        $this->withHeader('X-Razorpay-Signature', 'invalid-signature')
            ->postJson('/api/v4/webhooks/razorpay', [
                'event' => 'payment.captured',
            ])
            ->assertBadRequest()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'BAD_REQUEST');
    }
}
