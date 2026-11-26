<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;

/**
 * Base of the tests of the disallowed-calls entries in
 * tests/PHPStan/worker-safety.neon. The entries are loaded from that file, with
 * the checked paths pointed at tests/PHPStan/Fixtures/Worker/. Larastan is loaded as in
 * phpstan.neon, because it changes how facade calls resolve. The error handlers of
 * the Laravel application it boots are removed again (WorkerRuleTestCase).
 *
 * @template TRule of Rule
 *
 * @extends WorkerRuleTestCase<TRule>
 */
abstract class DisallowedCallsTestCase extends WorkerRuleTestCase
{
    protected const FIXTURE = __DIR__.'/../Fixtures/Worker/ProcessState.php';

    protected const MIDDLEWARE = __DIR__.'/../Fixtures/Worker/Middleware/SetsLocale.php';

    protected const CONFIG = 'writing the configuration at runtime keeps the value for every later request; pass the value per call.';

    protected const VIEW = 'the view factory lives as long as the worker, so the next request without this code sees the shared value; pass the data to the view or share it from Inertia per request.';

    protected const LOCALE = 'set the locale per request in a middleware, which Octane resets.';

    public static function getAdditionalConfigFiles(): array
    {
        return [...parent::getAdditionalConfigFiles(), __DIR__.'/worker-safety-fixtures.neon'];
    }

    /**
     * @param  class-string<TRule>  $rule
     * @return TRule
     */
    protected function ruleOf(string $rule): Rule
    {
        return self::getContainer()->getByType($rule);
    }
}
