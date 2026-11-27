<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Privacy;

use PHPStan\Rules\Rule;
use Tests\PHPStan\Rules\WorkerRuleTestCase;

/**
 * @extends WorkerRuleTestCase<OnlyEmailConfirmationWritesAccountEmailRule>
 */
final class OnlyEmailConfirmationWritesAccountEmailRuleTest extends WorkerRuleTestCase
{
    protected function getRule(): Rule
    {
        return new OnlyEmailConfirmationWritesAccountEmailRule(['App\\Models\\User'], ['App\\Fixtures\\Privacy\\AllowedWriter']);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [...parent::getAdditionalConfigFiles(), __DIR__.'/../../../../vendor/larastan/larastan/extension.neon'];
    }

    public function test_it_reports_writes_of_the_account_address_outside_email_confirmation(): void
    {
        $message = OnlyEmailConfirmationWritesAccountEmailRule::MESSAGE;

        $this->analyse([__DIR__.'/../../Fixtures/Privacy/AccountEmailWrites.php'], array_map(
            fn (int $line) => [$message, $line],
            range(22, 34),
        ));
    }

    public function test_it_lets_the_allowed_class_write(): void
    {
        $this->analyse([__DIR__.'/../../Fixtures/Privacy/AllowedWriter.php'], []);
    }
}
