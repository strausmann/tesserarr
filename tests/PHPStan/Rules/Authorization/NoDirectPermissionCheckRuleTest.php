<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Authorization;

use PHPStan\Rules\Rule;
use Tests\PHPStan\Rules\WorkerRuleTestCase;

/**
 * @extends WorkerRuleTestCase<NoDirectPermissionCheckRule>
 */
final class NoDirectPermissionCheckRuleTest extends WorkerRuleTestCase
{
    private const MESSAGE = 'Call to %s() is a permission check of its own. Ask the gate (can: middleware, $this->authorize(), Gate::inspect()), which asks App\Authorization\Authorizer (ADR-0016).';

    protected function getRule(): Rule
    {
        return new NoDirectPermissionCheckRule(['App\\Fixtures\\Authorization\\AllowedChecker'], ['App\\']);
    }

    public function test_it_reports_permission_and_role_checks_outside_the_authorizer(): void
    {
        $this->analyse([__DIR__.'/../../Fixtures/Authorization/PermissionChecks.php'], [
            [sprintf(self::MESSAGE, 'hasPermissionTo'), 19],
            [sprintf(self::MESSAGE, 'hasRole'), 20],
            [sprintf(self::MESSAGE, 'hasAnyRole'), 21],
            [sprintf(self::MESSAGE, 'hasAnyPermission'), 22],
            [sprintf(self::MESSAGE, 'checkPermissionTo'), 23],
        ]);
    }
}
