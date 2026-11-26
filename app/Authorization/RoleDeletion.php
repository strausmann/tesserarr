<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Authorization;

use App\Audit\Actor;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\Permission\Models\Role;

/**
 * Deletes an own role; its members get the role "user".
 *
 * Who may: the actor holds every permission of the role (RoleGuard), and,
 * when the role has members, every permission of "user" as well, because the
 * members receive it. The members are read inside the transaction after the
 * role row is locked FOR UPDATE: an assignment of this role inserts into
 * model_has_roles, whose foreign key takes a KEY SHARE lock on the same row.
 * So an assignment still open makes the deletion wait and is then counted as
 * a member, and an assignment that starts later waits for the deletion and
 * fails on the deleted role. No member is moved without the check.
 */
final class RoleDeletion
{
    public function __construct(
        private readonly RoleGuard $guard,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @return int the number of members moved to the role "user"
     *
     * @throws BuiltInRoleImmutable
     * @throws PrivilegeEscalation
     */
    public function delete(Actor $by, Role $role): int
    {
        $this->guard->assertMayChangePermissions($by, $role);

        return $this->db->transaction(function () use ($by, $role): int {
            /** @var Role|null $locked */
            $locked = Role::query()->whereKey($role->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                throw (new ModelNotFoundException)->setModel(Role::class, [$role->getKey()]);
            }

            $members = User::role($locked)->get();
            if ($members->isNotEmpty()) {
                $this->guard->assertCanGrant($by, Roles::permissionsOf(Roles::USER));
            }

            foreach ($members as $member) {
                $member->syncRoles([Roles::USER]);
            }
            $locked->delete();

            return $members->count();
        });
    }
}
