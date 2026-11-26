<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\RaceScenarios;

use App\Authorization\LastOperatorProtected;
use App\Authorization\RoleGuard;
use App\Authorization\Roles;
use App\Models\User;

/**
 * Demotes the operator named in the first argument to the role "user"
 * through RoleGuard::assertKeepsAnOperator, which stops at the barrier point
 * `operators.check` after the change and before it counts the operators.
 */
final class Demote implements RaceScenario
{
    public function __construct(private readonly RoleGuard $guard) {}

    public function run(array $arguments): mixed
    {
        $user = User::query()->where('username', $arguments[0])->firstOrFail();

        try {
            $this->guard->assertKeepsAnOperator(fn () => $user->syncRoles([Roles::USER]));
        } catch (LastOperatorProtected) {
            return ['demoted' => false];
        }

        return ['demoted' => true];
    }
}
