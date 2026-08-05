<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {

            Route::middleware('api')
                ->prefix('api/v1/auth')
                ->group(base_path('routes/auth-api.php'));

            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/kulsah-api.php'));

            Route::middleware('api')
                ->prefix('api/v1/developer')
                ->group(base_path('routes/developer-api.php'));
        }
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'middleware' => ['auth:sanctum'],
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })
    ->create();
