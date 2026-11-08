<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class V4PollApiTest extends TestCase
{
    public function test_customer_poll_routes_require_trusted_authentication(): void
    {
        foreach ([
            ['GET', 'api/v4/polls'],
            ['GET', 'api/v4/polls/{poll}'],
            ['POST', 'api/v4/polls/{poll}/vote'],
            ['DELETE', 'api/v4/polls/{poll}/vote'],
        ] as [$method, $uri]) {
            $route = collect(Route::getRoutes()->getRoutes())->first(
                fn ($route) => in_array($method, $route->methods(), true) && $route->uri() === $uri
            );

            $this->assertNotNull($route, "Missing {$method} {$uri}");
            $this->assertContains('auth.token', $route->gatherMiddleware(), $uri);
        }
    }
}
