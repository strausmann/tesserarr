<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture of EventListenerRegistrationRule: listeners registered outside a
// service provider. Analysed by PHPStan in the rule tests, never loaded.

namespace App\Fixtures\Events;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

final class RemembersTheRequest
{
    public function __construct(private readonly Dispatcher $events) {}

    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        Event::listen('shares.changed', fn () => $request->user());
        Event::subscribe(self::class);
        $this->events->listen('shares.changed', fn () => $request->ip());
        app('events')->listen('shares.changed', fn () => null);
        Event::dispatch('shares.changed');

        return $next($request);
    }
}
