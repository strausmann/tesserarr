<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Authorization;

use PHPStan\Rules\Rule;
use Tests\PHPStan\Rules\WorkerRuleTestCase;

/**
 * @extends WorkerRuleTestCase<NoUserLookupByEmailRule>
 */
final class NoUserLookupByEmailRuleTest extends WorkerRuleTestCase
{
    private const MESSAGE = 'An account is never looked up by its e-mail address: the address is contact data and not unique. Look the account up by username or identity (Spec 16.16).';

    protected function getRule(): Rule
    {
        return new NoUserLookupByEmailRule(['App\\Models\\User']);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [...parent::getAdditionalConfigFiles(), __DIR__.'/../../../../vendor/larastan/larastan/extension.neon'];
    }

    public function test_it_reports_lookups_of_users_by_email(): void
    {
        $this->analyse([__DIR__.'/../../Fixtures/Authorization/EmailLookups.php'], [
            [self::MESSAGE, 20],
            [self::MESSAGE, 21],
            [self::MESSAGE, 22],
            [self::MESSAGE, 23],
            [self::MESSAGE, 24],
            [self::MESSAGE, 25],
            [self::MESSAGE, 26],
        ]);
    }
}
