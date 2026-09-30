<?php

use App\Http\Middleware\AdminTokenMiddleware;
use App\Http\Middleware\ApiClientContext;
use App\Http\Middleware\ApiKeyMiddleware;
use App\Http\Middleware\AuthTokenMiddleware;
use App\Http\Middleware\OwnerDeviceMiddleware;
use App\Http\Middleware\V4ResponseEnvelope;
use App\Isp\Http\Middleware\EnsureActiveUser as EnsureActiveIspUser;
use App\Isp\Http\Middleware\EnsureAdmin as EnsureIspAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('isp/*') ? route('isp.login') : null
        );

        // ✅ Register route middleware
        $middleware->alias([
            'api.key' => ApiKeyMiddleware::class,
            'auth.token' => AuthTokenMiddleware::class,
            'owner.device' => OwnerDeviceMiddleware::class,
            'admin.token' => AdminTokenMiddleware::class,
            'api.client' => ApiClientContext::class,
            'api.v4' => V4ResponseEnvelope::class,
            'isp.active' => EnsureActiveIspUser::class,
            'isp.admin' => EnsureIspAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
