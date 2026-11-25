<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use Tests\PHPStan\Rules\Worker\NoStaticVariableRule;

/**
 * @extends WorkerRuleTestCase<NoStaticVariableRule>
 */
final class NoStaticVariableRuleTest extends WorkerRuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoStaticVariableRule(['App\\']);
    }

    public function test_it_reports_static_variables_without_a_justification(): void
    {
        $this->analyse([__DIR__.'/../Fixtures/StaticState.php'], [
            ['Static variable $seen outlives the request in worker mode. Use a scoped binding or a per-request value, or justify it with a "worker-safe: <reason>" comment.', 21],
        ]);
    }

    public function test_it_ignores_code_outside_the_checked_namespaces(): void
    {
        $this->analyse([__DIR__.'/../Fixtures/OutsideApp/StaticElsewhere.php'], []);
    }
}
