<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture: a service provider registers listeners once per worker, in
// register() or boot(). Anywhere else in a provider it is still refused.

namespace App\Providers\Fixture;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Event::listen('shares.changed', fn () => null);
    }

    public function boot(): void
    {
        $this->app['events']->listen('shares.changed', fn () => null);
        $this->app->booted(function (): void {
            Event::listen('shares.changed', fn () => null);
        });
    }

    public function later(): void
    {
        Event::listen('shares.changed', fn () => null);
    }
}
