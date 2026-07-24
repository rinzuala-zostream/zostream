<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class V4ApiTest extends TestCase
{
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
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v4/admin/'));

        $this->assertNotEmpty($adminRoutes);

        foreach ($adminRoutes as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth.token', $middleware, $route->uri());
            $this->assertContains('admin.token', $middleware, $route->uri());
        }
    }

    public function test_v4_mutations_are_authenticated_except_for_explicit_entry_points(): void
    {
        $publicMutations = [
            'api/v4/auth/otp/request',
            'api/v4/auth/admin/otp/request',
            'api/v4/auth/otp/verify',
            'api/v4/auth/tokens/refresh',
            'api/v4/qr-sessions',
            'api/v4/webhooks/razorpay',
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
}
