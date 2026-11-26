<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture: a middleware may set the locale per request (Octane resets it).

namespace App\Fixtures\Worker\Middleware;

use Illuminate\Support\Facades\App;

final class SetsLocale
{
    public function handle(): void
    {
        App::setLocale('de');
        app()->setLocale('de');
    }
}
