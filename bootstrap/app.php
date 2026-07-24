<?php

use App\Http\Middleware\AdminTokenMiddleware;
use App\Http\Middleware\ApiClientContext;
use App\Http\Middleware\ApiKeyMiddleware;
use App\Http\Middleware\AuthTokenMiddleware;
use App\Http\Middleware\V4ResponseEnvelope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ✅ Register route middleware
        $middleware->alias([
            'api.key' => ApiKeyMiddleware::class,
            'auth.token' => AuthTokenMiddleware::class,
            'admin.token' => AdminTokenMiddleware::class,
            'api.client' => ApiClientContext::class,
            'api.v4' => V4ResponseEnvelope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
