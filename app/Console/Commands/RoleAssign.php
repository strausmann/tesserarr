<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Authorization\LastOperatorProtected;
use App\Authorization\Permission;
use App\Authorization\PrivilegeEscalation;
use App\Authorization\RoleGuard;
use App\Authorization\Roles;
use App\Console\Commands\Concerns\FindsAccounts;
use App\Console\Support\TerminalText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Gives an account one role, from the operator's console. The usual way to
 * make the first operator after the first invitation is redeemed.
 */
final class RoleAssign extends Command
{
    use FindsAccounts;

    protected $signature = 'tesserarr:role:assign
        {username : the account, by username (never an e-mail address)}
        {role : operator, user or the name of an own role}';

    protected $description = 'Give an account a role (replaces its current role); keeps at least one active operator';

    public function handle(RoleGuard $guard, AuditLogger $audit): int
    {
        $user = $this->findAccount((string) $this->argument('username'));
        if ($user === null) {
            return self::FAILURE;
        }

        $roleName = (string) $this->argument('role');
        $role = Role::query()->where('guard_name', Roles::GUARD)->where('name', $roleName)->first();
        if ($role === null) {
            $this->error(sprintf('No role "%s". Roles: %s.', TerminalText::safe($roleName), implode(', ', Role::query()->where('guard_name', Roles::GUARD)->orderBy('id')->pluck('name')->all())));

            return self::FAILURE;
        }

        $actor = Actor::console();

        try {
            DB::transaction(function () use ($guard, $audit, $actor, $user, $role): void {
                // Locks first, then what the change reads and checks, then the
                // change and its audit entry, all in one transaction.
                $guard->lockAccounts([$user]);
                $previous = $user->unsetRelation('roles')->getRoleNames()->values()->all();
                $locked = $guard->lockRole($role, forUpdate: false);
                $guard->assertCanGrant($actor, RoleGuard::permissionsOfRole($locked));
                $guard->assertKeepsAnOperator(fn () => $user->syncRoles([$locked]));
                $audit->record(AuditAction::RoleAssigned, $actor, $user, ['role' => $locked->name, 'previous' => $previous]);
            }, 3);
        } catch (PrivilegeEscalation $e) {
            $audit->record(AuditAction::RoleAssigned, $actor, $user, ['role' => $role->name, 'refused' => Permission::values($e->permissions)], AuditOutcome::Denied);
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (LastOperatorProtected) {
            $audit->record(AuditAction::RoleAssigned, $actor, $user, ['role' => $role->name], AuditOutcome::Denied);
            $this->error(sprintf('Refused: %s is the last active operator. Make another account an operator first.', $this->describe($user)));

            return self::FAILURE;
        }

        $this->info(sprintf('Role %s given to %s.', TerminalText::safe((string) $role->name), $this->describe($user)));

        return self::SUCCESS;
    }
}
