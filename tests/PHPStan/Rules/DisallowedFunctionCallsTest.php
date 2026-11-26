<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use Spaze\PHPStan\Rules\Disallowed\Calls\FunctionCalls;

/**
 * @extends DisallowedCallsTestCase<FunctionCalls>
 */
final class DisallowedFunctionCallsTest extends DisallowedCallsTestCase
{
    private const CONFIG_HELPER = 'only reading by key is allowed: config([...]) writes the configuration at runtime and keeps the value for every later request; pass the value per call.';

    private const PROCESS = 'it changes the whole process, also for requests running at the same time.';

    private const LATER = 'it stays set for every later request of the worker.';

    protected function getRule(): Rule
    {
        return $this->ruleOf(FunctionCalls::class);
    }

    public function test_it_refuses_calls_that_end_or_change_the_worker_process(): void
    {
        $this->analyse([self::FIXTURE], [
            ['Calling register_shutdown_function() is forbidden, in worker mode it runs only when the worker stops, inside another request.', 26],
            ['Calling setlocale() is forbidden, '.self::PROCESS, 27],
            ['Calling umask() is forbidden, '.self::PROCESS, 28],
            ['Calling chdir() is forbidden, '.self::PROCESS, 29],
            ['Calling putenv() is forbidden, '.self::LATER, 30],
            ['Calling ini_set() is forbidden, '.self::LATER, 31],
            ['Calling date_default_timezone_set() is forbidden, '.self::LATER, 32],
            ['Calling set_error_handler() is forbidden, '.self::LATER, 33],
            ['Calling set_exception_handler() is forbidden, '.self::LATER, 34],
            ['Calling stream_context_set_default() is forbidden, '.self::LATER, 35],
            ['Calling session_start() is forbidden, use the Laravel session.', 36],
            ['Calling config() is forbidden, '.self::CONFIG_HELPER, 41],
            ['Calling config() is forbidden, '.self::CONFIG_HELPER, 43],
        ]);
    }
}
