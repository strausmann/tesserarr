<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\RaceScenarios;

use App\Audit\Actor;
use App\Authorization\PrivilegeEscalation;
use App\Authorization\RoleDeletion;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Deletes the role named in the second argument as the account named in the
 * first (App\Authorization\RoleDeletion, as the role page does).
 */
final class DeleteRole implements RaceScenario
{
    public function __construct(private readonly RoleDeletion $deletion) {}

    public function run(array $arguments): mixed
    {
        $actor = User::query()->where('username', $arguments[0])->firstOrFail();
        $role = Role::query()->where('name', $arguments[1])->firstOrFail();

        try {
            return ['deleted' => true, 'members' => $this->deletion->delete(Actor::user($actor), $role)];
        } catch (PrivilegeEscalation $e) {
            return ['deleted' => false, 'refused' => $e->getMessage()];
        }
    }
}
