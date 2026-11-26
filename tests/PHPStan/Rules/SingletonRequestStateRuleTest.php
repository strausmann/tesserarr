<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use Tests\PHPStan\Rules\Worker\SingletonRequestStateRule;

/**
 * @extends WorkerRuleTestCase<SingletonRequestStateRule>
 */
final class SingletonRequestStateRuleTest extends WorkerRuleTestCase
{
    private const MESSAGE = 'Singleton %s is built from request state (%s). The value of the first request stays for every later request in worker mode. Bind it with scoped() or read the value per call, or justify it with a "worker-safe: <reason>" comment.';

    protected function getRule(): Rule
    {
        return new SingletonRequestStateRule($this->createReflectionProvider());
    }

    public function test_it_reports_singletons_built_from_request_state(): void
    {
        $this->analyse([__DIR__.'/../Fixtures/Singletons.php'], [
            [sprintf(self::MESSAGE, 'App\\Fixtures\\Singletons\GreetsTheRequest', 'constructor parameter $request: Illuminate\Http\Request'), 72],
            [sprintf(self::MESSAGE, 'greeter', 'constructor parameter $guard: Illuminate\Contracts\Auth\Guard'), 73],
            [sprintf(self::MESSAGE, 'App\\Fixtures\\Singletons\TakesAString', 'config()'), 75],
            [sprintf(self::MESSAGE, 'App\\Fixtures\\Singletons\TakesAString', 'request()'), 76],
            [sprintf(self::MESSAGE, 'App\\Fixtures\\Singletons\TakesAString', '$app[\'request\']'), 77],
            [sprintf(self::MESSAGE, 'App\\Fixtures\\Singletons\TakesAString', 'Illuminate\Support\Facades\Auth::id()'), 78],
            [sprintf(self::MESSAGE, 'App\\Fixtures\\Singletons\TakesAString', 'make(Illuminate\Contracts\Config\Repository)'), 79],
            [sprintf(self::MESSAGE, 'App\\Fixtures\\Singletons\TakesAString', 'closure parameter $request: Illuminate\Http\Request'), 80],
            [sprintf(self::MESSAGE, 'App\\Fixtures\\Singletons\KnowsTheUser', 'make(Illuminate\Contracts\Auth\Guard)'), 81],
            [sprintf(self::MESSAGE, 'App\\Fixtures\\Singletons\GreetsTheRequest', 'constructor parameter $request: Illuminate\Http\Request'), 82],
            [sprintf(self::MESSAGE, 'App\\Fixtures\\Singletons\GreetsTheRequest', 'constructor parameter $request: Illuminate\Http\Request'), 83],
            [sprintf(self::MESSAGE, 'App\Fixtures\Singletons\KeepsTheContainer', 'constructor parameter $container: Illuminate\Contracts\Container\Container'), 91],
            [sprintf(self::MESSAGE, 'App\Fixtures\Singletons\TakesAString', 'request()'), 93],
            [sprintf(self::MESSAGE, 'App\Fixtures\Singletons\TakesAString', '$app[\'request\']'), 100],
        ]);
    }
}
