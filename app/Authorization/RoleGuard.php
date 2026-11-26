<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Authorization;

use App\Audit\Actor;
use App\Concurrency\ConcurrencyBarrier;
use App\Concurrency\InvariantGuard;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Spatie\Permission\Models\Role;

/**
 * The guard rules of ADR-0016 point 4: nobody grants a permission they lack,
 * the built-in roles stay as they are, and at least one active operator
 * remains.
 */
final class RoleGuard
{
    public const OPERATORS_LOCK = 'tesserarr.operators';

    public function __construct(
        private readonly InvariantGuard $invariants,
        private readonly ConcurrencyBarrier $barrier,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * May $actor hand out $permissions?
     *
     * - A user: only permissions they hold now (and only while not locked).
     * - The console: everything assignable to users; when $owner is given
     *   (a token for that owner), only what the owner holds.
     * - A token: what its owner holds; a satellite: nothing.
     * - `satellite.execute` never, to anybody.
     *
     * @param  list<Permission>  $permissions
     *
     * @throws PrivilegeEscalation
     */
    public function assertCanGrant(Actor $actor, array $permissions, ?User $owner = null): void
    {
        $never = array_values(array_filter($permissions, fn (Permission $p) => ! in_array($p, Permission::assignableToUsers(), true)));
        if ($never !== []) {
            throw new PrivilegeEscalation($never, 'no account may hold');
        }

        $ceiling = $this->grantableBy($actor, $owner);
        $refused = array_values(array_filter($permissions, fn (Permission $p) => ! in_array($p, $ceiling, true)));
        if ($refused !== []) {
            throw new PrivilegeEscalation(Permission::ordered($refused));
        }
    }

    /**
     * What $actor may hand out (for $owner, when given), in catalogue order:
     * the ceiling assertCanGrant() checks against. The role page uses it to
     * show which boxes the actor can tick.
     *
     * @return list<Permission>
     */
    public function grantableBy(Actor $actor, ?User $owner = null): array
    {
        $ceiling = match ($actor->type) {
            Actor::USER, Actor::TOKEN => $actor->user === null || $actor->user->isBlocked() ? [] : $actor->user->grantedPermissions(),
            Actor::CLI => Permission::assignableToUsers(),
            Actor::SATELLITE => [],
        };

        if ($owner !== null) {
            $held = $owner->isBlocked() ? [] : $owner->grantedPermissions();
            $ceiling = array_values(array_filter($ceiling, fn (Permission $p) => in_array($p, $held, true)));
        }

        return Permission::ordered(array_values(array_filter(
            $ceiling,
            fn (Permission $p) => in_array($p, Permission::assignableToUsers(), true),
        )));
    }

    /**
     * The permissions of a built-in role cannot be changed and the role cannot
     * be deleted; for an own role the actor needs every permission it holds
     * (otherwise they could remove a permission they cannot give back).
     *
     * @throws BuiltInRoleImmutable
     * @throws PrivilegeEscalation
     */
    public function assertMayChangePermissions(Actor $actor, Role $role): void
    {
        $name = (string) $role->getAttribute('name');
        if (Roles::isBuiltIn($name)) {
            throw new BuiltInRoleImmutable($name);
        }

        $this->assertCanGrant($actor, self::permissionsOfRole($role));
    }

    /**
     * Runs $change and keeps at least one active operator (role operator,
     * not locked). Covers demoting, removing the role, deleting and locking.
     * The change is rolled back and LastOperatorProtected thrown when none
     * would be left. Race barrier `operators.check` after the change, before
     * the count.
     *
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     *
     * @throws LastOperatorProtected
     */
    public function assertKeepsAnOperator(callable $change): mixed
    {
        // An installation without any active operator yet (before the first
        // one is named) has nothing to protect; every other state must keep
        // one. Counted inside the transaction, before the change.
        [, $result] = $this->invariants->underLock(
            self::OPERATORS_LOCK,
            function () use ($change): array {
                $before = $this->activeOperators();
                $result = $change();
                $this->barrier->reached('operators.check');

                return [$before, $result];
            },
            fn (array $outcome): bool => $outcome[0] === 0 || $this->activeOperators() >= 1,
            new LastOperatorProtected,
        );

        return $result;
    }

    /** @return list<Permission> */
    public static function permissionsOfRole(Role $role): array
    {
        /** @var list<string> $names */
        $names = $role->permissions()->pluck('name')->all();

        return Permission::ordered(array_values(array_filter(array_map(
            fn (string $name) => Permission::tryFrom($name),
            $names,
        ))));
    }

    private function activeOperators(): int
    {
        return (int) $this->db->table('users')
            ->join('model_has_roles', function ($join) {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', (new User)->getMorphClass());
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', Roles::OPERATOR)
            ->where('roles.guard_name', Roles::GUARD)
            ->whereNull('users.locked_at')
            ->count();
    }
}
