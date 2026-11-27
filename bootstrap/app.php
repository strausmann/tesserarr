<?php

declare(strict_types=1);

use App\Http\Middleware\AccessLog;
use App\Http\Middleware\AddVersionHeader;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LimitRequestBody;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SignOutLockedAccount;
use App\Http\PersonalInput;
use App\Http\TrustedHosts;
use App\Runtime\InstanceRole;
use App\Runtime\RoleBoot;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    // The role is resolved once, after .env is loaded (AppServiceProvider,
    // RoleBoot::apply); routes and channels read it from the container.
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => RoleBoot::routes(app(InstanceRole::class)),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance']);

        // Only the host of APP_URL (plus loopback and TESSERARR_TRUSTED_HOSTS);
        // a forged Host header is refused (task 8a). Laravel skips the check
        // in the local and testing environments.
        $middleware->trustHosts(at: fn () => TrustedHosts::patterns(), subdomains: false);

        // First, so it also logs responses that later middleware short-circuits.
        $middleware->prepend(AccessLog::class);
        $middleware->append(LimitRequestBody::class);
        // On every answer of the application: pages, Inertia, /api/v1/app and
        // /api/v1 (lib/updates.svelte.ts compares it with the bundle).
        $middleware->append(AddVersionHeader::class);

        $middleware->alias([LimitRequestBody::ALIAS => LimitRequestBody::class]);

        // Token API (/api/v1); filled with authentication in a later step.
        $middleware->group('token-api', []);

        $middleware->web(append: [
            // Before anything reads the account: a locked one is signed out.
            SignOutLockedAccount::class,
            HandleAppearance::class,
            // After the session (the user's profile), before the page props.
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A failed validation flashes the input into the session; personal
        // data and tokens never go there (task 8a). `login` is the username
        // or, from task 11, an address.
        $exceptions->dontFlash(PersonalInput::FIELDS);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
