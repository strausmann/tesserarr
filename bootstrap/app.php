<?php

declare(strict_types=1);

use App\Http\Middleware\AccessLog;
use App\Http\Middleware\AddVersionHeader;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LimitRequestBody;
use App\Http\Middleware\LimitTokenPathByAddress;
use App\Http\Middleware\RequireAppHeader;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SignOutLockedAccount;
use App\Http\PersonalInput;
use App\Http\TrustedHosts;
use App\Providers\AppServiceProvider;
use App\Runtime\InstanceRole;
use App\Runtime\RoleBoot;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;

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

        // ADR-0007: two paths, each with exactly one sign-in check.
        //
        // The session path (/api/v1/app/*) for the web interface: the session
        // cookie and nothing else (auth:web; a bearer token is never read
        // here), then the app's own header (403 missing_app_header) and the
        // CSRF token on top of SameSite.
        $middleware->group('session-api', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            'auth:web',
            SignOutLockedAccount::class,
            RequireAppHeader::class,
            ValidateCsrfToken::class,
            SubstituteBindings::class,
            SetLocale::class,
        ]);

        // The token path (/api/v1/*) for other systems: a bearer token and
        // nothing else. No cookie, session or CSRF middleware: with
        // sanctum.guard = [] the session is never consulted, and none is
        // started, so the path sets no cookie either.
        //
        // No CSRF check, on purpose: a browser never sends an Authorization
        // header by itself, and a foreign page cannot set one without a CORS
        // preflight that fails. A "safety" CSRF check would tie this path to
        // browser state and lock the arr apps out (ADR-0007 point 5).
        //
        // LimitRequestBody runs before it as global middleware (appended
        // above), so an oversized body is refused before the token is looked up.
        //
        // Two rate limits: per source address before the token is looked up
        // (LimitTokenPathByAddress), so a flood of made-up bearers is cut off
        // early; and per token after the sign-in (AppServiceProvider::
        // limitTheTokenApi). Laravel sorts `throttle:` behind `auth:` anyway,
        // so only a token that signed in counts against its own budget.
        $middleware->group('token-api', [
            LimitTokenPathByAddress::class,
            'auth:sanctum',
            'throttle:'.AppServiceProvider::TOKEN_API_TOKEN_LIMITER,
            SubstituteBindings::class,
        ]);

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
