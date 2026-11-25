<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture of the disallowed calls in tests/PHPStan/worker-safety.neon:
// analysed by PHPStan in the rule tests, never loaded.

namespace App\Fixtures\Worker;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;

final class ProcessState
{
    public function functions(): void
    {
        exit(1);
    }

    public function process(): void
    {
        register_shutdown_function(static fn () => null);
        setlocale(LC_ALL, 'de_DE');
        umask(0077);
        chdir('/tmp');
        putenv('A=1');
        ini_set('precision', '10');
        date_default_timezone_set('UTC');
        set_error_handler(static fn (): bool => true);
        set_exception_handler(static fn () => null);
        stream_context_set_default([]);
        session_start();
    }

    public function configuration(): mixed
    {
        config(['app.name' => 'leaked']);
        Config::set('app.name', 'leaked');
        config()->set('app.name', 'leaked');

        return config('app.name');
    }

    public function views(): void
    {
        View::share('user', 'alice');
        view()->share('user', 'alice');
    }

    public function locale(): void
    {
        App::setLocale('de');
        app()->setLocale('de');
    }

    public function environment(): mixed
    {
        $_ENV['TENANT'] = 'alice';

        return $GLOBALS['tenant'] ?? null;
    }
}
