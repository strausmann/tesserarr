<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Authorization;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
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
 * fails on the deleted role. No member is moved without the check. The
 * role's own permissions are checked after the lock as well: a permission
 * added meanwhile (role_has_permissions, same KEY SHARE lock) is counted.
 */
final class RoleDeletion
{
    /** A transaction PostgreSQL ends as a deadlock victim is run again. */
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly RoleGuard $guard,
        private readonly ConnectionInterface $db,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int the number of members moved to the role "user"
     *
     * @throws BuiltInRoleImmutable
     * @throws PrivilegeEscalation
     */
    public function delete(Actor $by, Role $role): int
    {
        return $this->db->transaction(function () use ($by, $role): int {
            // Lock order as in every role change: the accounts first (in id
            // order), the role after them. The members are read once to know
            // which accounts to lock and again after the role is locked: an
            // account given another role meanwhile is no member any more and
            // keeps that role; one that joined meanwhile is locked as well.
            $candidates = User::role($role)->orderBy('id')->get(['id']);
            $this->guard->lockAccounts(array_values($candidates->all()), [$by->user]);
            $locked = $candidates->pluck('id')->all();

            // Every check after the locks, on the role as it is now.
            $roleNow = $this->guard->lockRole($role, forUpdate: true);
            $this->guard->assertMayChangePermissions($by, $roleNow);

            foreach (User::role($roleNow)->whereNotIn('id', $locked)->orderBy('id')->get(['id']) as $late) {
                $this->guard->lockAccount($late);
            }
            $members = User::role($roleNow)->get();
            if ($members->isNotEmpty()) {
                $this->guard->assertCanGrant($by, Roles::permissionsOf(Roles::USER));
            }

            foreach ($members as $member) {
                $member->syncRoles([Roles::USER]);
            }
            $roleNow->delete();
            // In the transaction: no deletion without its entry.
            $this->audit->record(AuditAction::RoleDeleted, $by, null, ['role' => (string) $roleNow->getAttribute('name'), 'members' => $members->count()]);

            return $members->count();
        }, self::ATTEMPTS);
    }
}
