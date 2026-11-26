<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use Spaze\PHPStan\Rules\Disallowed\Calls\ExitDieCalls;

/**
 * die() is no fixture case: Pint rewrites it to exit() (no_alias_language_construct_call),
 * and the entry in worker-safety.neon lists both.
 *
 * @extends DisallowedCallsTestCase<ExitDieCalls>
 */
final class DisallowedExitTest extends DisallowedCallsTestCase
{
    private const MESSAGE = 'it ends the worker script and runs the shutdown functions of every request so far; throw an exception or return a response.';

    protected function getRule(): Rule
    {
        return $this->ruleOf(ExitDieCalls::class);
    }

    public function test_it_refuses_exit_and_die(): void
    {
        $this->analyse([self::FIXTURE], [
            ['Calling exit() is forbidden, '.self::MESSAGE, 21],
        ]);
    }
}
