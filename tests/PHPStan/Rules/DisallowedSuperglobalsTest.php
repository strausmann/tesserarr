<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use Spaze\PHPStan\Rules\Disallowed\Usages\VariableUsages;

/**
 * @extends DisallowedCallsTestCase<VariableUsages>
 */
final class DisallowedSuperglobalsTest extends DisallowedCallsTestCase
{
    private const MESSAGE = 'FrankenPHP does not reset $_ENV between requests; read configuration through config(), request data through the Request.';

    protected function getRule(): Rule
    {
        return $this->ruleOf(VariableUsages::class);
    }

    public function test_it_refuses_the_environment_and_global_superglobals(): void
    {
        $this->analyse([self::FIXTURE], [
            ['Using $_ENV is forbidden, '.self::MESSAGE, 62],
            ['Using $GLOBALS is forbidden, '.self::MESSAGE, 64],
        ]);
    }
}
