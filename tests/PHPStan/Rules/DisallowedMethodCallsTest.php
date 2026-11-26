<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use Spaze\PHPStan\Rules\Disallowed\Calls\MethodCalls;

/**
 * @extends DisallowedCallsTestCase<MethodCalls>
 */
final class DisallowedMethodCallsTest extends DisallowedCallsTestCase
{
    protected function getRule(): Rule
    {
        return $this->ruleOf(MethodCalls::class);
    }

    public function test_it_refuses_method_calls_that_keep_state_between_requests(): void
    {
        $this->analyse([self::FIXTURE], [
            ['Calling Illuminate\Contracts\Config\Repository::set() (as Illuminate\Config\Repository::set()) is forbidden, '.self::CONFIG, 43],
            ['Calling Illuminate\Contracts\View\Factory::share() is forbidden, '.self::VIEW, 51],
            ['Calling Illuminate\Contracts\Foundation\Application::setLocale() (as Illuminate\Foundation\Application::setLocale()) is forbidden, '.self::LOCALE, 57],
        ]);
    }

    public function test_it_lets_a_middleware_set_the_locale(): void
    {
        $this->analyse([self::MIDDLEWARE], []);
    }
}
