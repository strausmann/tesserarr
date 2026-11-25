<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture of SingletonRequestStateRule: analysed by PHPStan in the rule
// tests, never loaded.

namespace App\Fixtures\Singletons;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

final class GreetsTheRequest
{
    public function __construct(private readonly Request $request) {}
}

final class KnowsTheUser
{
    public function __construct(private readonly Guard $guard) {}
}

final class Pure
{
    public function __construct(private readonly int $limit = 5) {}
}

final class TakesAString
{
    public function __construct(private readonly string $value) {}
}

final class KeepsTheContainer
{
    public function __construct(private readonly Container $container) {}
}

#[Singleton]
final class AttributeSingletonWithSession
{
    public function __construct(private readonly Store $session) {}
}

#[Scoped]
final class AttributeScopedWithSession
{
    public function __construct(private readonly Store $session) {}
}

#[Singleton]
final class AttributeSingletonPure
{
    public function __construct(private readonly Pure $pure) {}
}

final class Bindings extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GreetsTheRequest::class);
        $this->app->singleton('greeter', KnowsTheUser::class);
        $this->app->singleton(Pure::class);
        $this->app->singleton(TakesAString::class, fn (Application $app) => new TakesAString((string) config('app.name')));
        $this->app->singleton(TakesAString::class, fn () => new TakesAString((string) request()->ip()));
        $this->app->singleton(TakesAString::class, fn (Application $app) => new TakesAString((string) $app['request']->ip()));
        $this->app->singleton(TakesAString::class, fn (Application $app) => new TakesAString((string) Auth::id()));
        $this->app->singleton(TakesAString::class, fn (Application $app) => new TakesAString((string) $app->make(Repository::class)->get('app.name')));
        $this->app->singleton(TakesAString::class, fn (Request $request) => new TakesAString((string) $request->ip()));
        $this->app->singleton(KnowsTheUser::class, fn (Application $app) => new KnowsTheUser($app->make(Guard::class)));
        $this->app->singletonIf(GreetsTheRequest::class);
        App::singleton(GreetsTheRequest::class);

        $this->app->singleton(Pure::class, fn (Application $app) => new Pure($app->environment('testing') ? 1 : 5));
        $this->app->scoped(GreetsTheRequest::class);
        $this->app->bind(KnowsTheUser::class);
        $this->app->scoped(TakesAString::class, fn () => new TakesAString((string) request()->ip()));
        // worker-safe: built once at boot from the image's fixed APP_NAME, never written at runtime
        $this->app->singleton(TakesAString::class, fn () => new TakesAString((string) config('app.name')));
        $this->app->singleton(KeepsTheContainer::class);
    }
}
