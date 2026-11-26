<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use Tests\PHPStan\Rules\Worker\NoGlobalVariableRule;

/**
 * @extends WorkerRuleTestCase<NoGlobalVariableRule>
 */
final class NoGlobalVariableRuleTest extends WorkerRuleTestCase
{
    private const MESSAGE = 'Global variable $%s outlives the request in worker mode. Use a scoped binding or a per-request value, or justify it with a "worker-safe: <reason>" comment.';

    protected function getRule(): Rule
    {
        return new NoGlobalVariableRule(['App\\'], ['tests/PHPStan/Fixtures/Routes/*']);
    }

    public function test_it_reports_global_statements_without_a_justification(): void
    {
        $this->analyse([__DIR__.'/../Fixtures/GlobalState.php'], [
            [sprintf(self::MESSAGE, 'currentUser'), 17],
            [sprintf(self::MESSAGE, 'tenant'), 17],
        ]);
    }

    public function test_it_reports_global_statements_in_route_files(): void
    {
        $this->analyse([__DIR__.'/../Fixtures/Routes/globals.php'], [
            [sprintf(self::MESSAGE, 'user'), 13],
        ]);
    }

    public function test_it_ignores_code_outside_the_checked_namespaces_and_paths(): void
    {
        $this->analyse([__DIR__.'/../Fixtures/OutsideApp/StaticElsewhere.php'], []);
    }
}
