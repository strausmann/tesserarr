<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\RaceScenarios;

use App\Authorization\RoleGuard;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Gives the account named in the first argument the role named in the second,
 * through RoleGuard::assertKeepsAnOperator. It stops at the barrier point
 * `operators.check` with the assignment written but not committed, which is
 * the moment a concurrent deletion of that role must not miss.
 */
final class AssignRole implements RaceScenario
{
    public function __construct(private readonly RoleGuard $guard) {}

    public function run(array $arguments): mixed
    {
        $user = User::query()->where('username', $arguments[0])->firstOrFail();
        $role = Role::query()->where('name', $arguments[1])->firstOrFail();

        $this->guard->assertKeepsAnOperator(fn () => $user->syncRoles([$role]));

        return ['assigned' => true];
    }
}
