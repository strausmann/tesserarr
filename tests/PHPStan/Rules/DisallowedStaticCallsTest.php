<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use Spaze\PHPStan\Rules\Disallowed\Calls\StaticCalls;

/**
 * @extends DisallowedCallsTestCase<StaticCalls>
 */
final class DisallowedStaticCallsTest extends DisallowedCallsTestCase
{
    protected function getRule(): Rule
    {
        return $this->ruleOf(StaticCalls::class);
    }

    public function test_it_refuses_facade_calls_that_keep_state_between_requests(): void
    {
        $this->analyse([self::FIXTURE], [
            ['Calling Illuminate\Config\Repository::set() (as Illuminate\Support\Facades\Config::set()) is forbidden, '.self::CONFIG, 42],
            ['Calling Illuminate\View\Factory::share() (as Illuminate\Support\Facades\View::share()) is forbidden, '.self::VIEW, 50],
            ['Calling Illuminate\Support\Facades\App::setLocale() is forbidden, '.self::LOCALE, 56],
        ]);
    }

    public function test_it_lets_a_middleware_set_the_locale(): void
    {
        $this->analyse([self::MIDDLEWARE], []);
    }
}
