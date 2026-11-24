<?php

use App\Http\Middleware\AccessLog;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LimitRequestBody;
use App\Runtime\InstanceRole;
use App\Runtime\RoleBoot;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

// Fails the start on an unknown TESSERARR_ROLE (InvalidArgumentException).
$role = InstanceRole::fromEnv();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        channels: $role === InstanceRole::Central ? __DIR__.'/../routes/channels.php' : null,
        health: '/up',
        then: fn () => RoleBoot::routes($role),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // First, so it also logs responses that later middleware short-circuits.
        $middleware->prepend(AccessLog::class);
        $middleware->append(LimitRequestBody::class);

        $middleware->alias([LimitRequestBody::ALIAS => LimitRequestBody::class]);

        // Token API (/api/v1); filled with authentication in a later step.
        $middleware->group('token-api', []);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
