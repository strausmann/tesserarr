<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Authorization\BuiltInRoleImmutable;
use App\Authorization\Permission;
use App\Authorization\PrivilegeEscalation;
use App\Authorization\RoleDeletion;
use App\Authorization\RoleGuard;
use App\Authorization\Roles;
use App\Http\Controllers\Controller;
use App\Identity\Rules\DisplayName;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Roles and permissions (Roles.dc.html): the catalogue per role, own roles
 * between the two built-in ones. The built-in roles are shown, never changed.
 * `satellite.execute` is not offered: only satellite tokens carry it.
 */
final class RoleController extends Controller
{
    use RefusesGuardedChanges;

    /** The catalogue as the page groups it (the groups of the artboard). */
    private const GROUPS = [
        'users' => [Permission::UsersView, Permission::UsersManage, Permission::RolesManage, Permission::InvitationsCreate, Permission::InvitationsRevoke, Permission::LoginMethodsManage],
        'tokens' => [Permission::TokensManageOwn, Permission::TokensManageAll],
        'media' => [Permission::CatalogBrowse, Permission::CatalogViewAll, Permission::SharesManage, Permission::GrantsManage],
        'operations' => [Permission::StatusView, Permission::ConnectionsView, Permission::ConnectionsManage, Permission::SatellitesManage, Permission::AuditView, Permission::SettingsManage],
    ];

    public function __construct(
        private readonly RoleGuard $guard,
        private readonly AuditLogger $audit,
        private readonly RoleDeletion $deletion,
    ) {}

    public function index(#[CurrentUser] User $actor): Response
    {
        $roles = Role::query()->where('guard_name', Roles::GUARD)->with('permissions:id,name')->get()
            ->sortBy(fn (Role $role) => match ((string) $role->getAttribute('name')) {
                Roles::OPERATOR => '0',
                Roles::USER => '2',
                default => '1'.mb_strtolower((string) $role->getAttribute('name')),
            })->values();

        $members = DB::table('model_has_roles')->where('model_type', (new User)->getMorphClass())
            ->selectRaw('role_id, count(*) as members')->groupBy('role_id')->pluck('members', 'role_id');

        return Inertia::render('admin/Roles', [
            'groups' => array_map(fn (string $key, array $permissions) => [
                'key' => $key,
                'permissions' => Permission::values($permissions),
            ], array_keys(self::GROUPS), array_values(self::GROUPS)),
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->getKey(),
                'name' => $role->getAttribute('name'),
                'built_in' => Roles::isBuiltIn((string) $role->getAttribute('name')),
                'permissions' => Permission::values(RoleGuard::permissionsOfRole($role)),
                'members' => (int) ($members[$role->getKey()] ?? 0),
            ])->all(),
            'grantable' => Permission::values($this->guard->grantableBy(Actor::user($actor))),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $actor): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:64', new DisplayName, $this->newRoleName()],
            ...$this->permissionRules(),
        ]);
        $permissions = Permission::ordered(Permission::fromValues($validated['permissions'] ?? []));
        $name = trim((string) $validated['name']);
        $by = Actor::user($actor);

        try {
            $this->guard->assertCanGrant($by, $permissions);
        } catch (PrivilegeEscalation $e) {
            $this->audit->record(AuditAction::RoleCreated, $by, null, ['role' => $name, 'refused' => Permission::values($e->permissions)], AuditOutcome::Denied);

            return $this->refuse($request, 'privilege_escalation', 403, 'permissions', $e->getMessage(), $e->permissions);
        }

        $role = DB::transaction(function () use ($name, $permissions): Role {
            /** @var Role $role */
            $role = Role::create(['name' => $name, 'guard_name' => Roles::GUARD]);
            $role->syncPermissions(Permission::values($permissions));

            return $role;
        });

        $this->audit->record(AuditAction::RoleCreated, $by, $role, ['role' => $name, 'permissions' => Permission::values($permissions)]);

        return to_route('roles.index');
    }

    public function update(Request $request, Role $role, #[CurrentUser] User $actor): JsonResponse|RedirectResponse
    {
        $by = Actor::user($actor);
        $name = (string) $role->getAttribute('name');

        if (Roles::isBuiltIn($name)) {
            $this->audit->record(AuditAction::RoleChanged, $by, $role, ['role' => $name], AuditOutcome::Denied);

            return $this->refuse($request, 'built_in_role_immutable', 422, 'permissions', (new BuiltInRoleImmutable($name))->getMessage());
        }

        $validated = $request->validate($this->permissionRules());
        $before = RoleGuard::permissionsOfRole($role);
        $after = Permission::ordered(Permission::fromValues($validated['permissions'] ?? []));

        try {
            // Removing a permission needs it as much as adding one.
            $this->guard->assertMayChangePermissions($by, $role);
            $this->guard->assertCanGrant($by, $after);
        } catch (PrivilegeEscalation $e) {
            $this->audit->record(AuditAction::RoleChanged, $by, $role, ['role' => $name, 'refused' => Permission::values($e->permissions)], AuditOutcome::Denied);

            return $this->refuse($request, 'privilege_escalation', 403, 'permissions', $e->getMessage(), $e->permissions);
        }

        $role->syncPermissions(Permission::values($after));

        $this->audit->record(AuditAction::RoleChanged, $by, $role, [
            'role' => $name,
            'added' => Permission::values(array_values(array_diff_key(self::keyed($after), self::keyed($before)))),
            'removed' => Permission::values(array_values(array_diff_key(self::keyed($before), self::keyed($after)))),
        ]);

        return to_route('roles.index');
    }

    /**
     * Deletes an own role; its members get the role "user" (RoleDeletion).
     */
    public function destroy(Request $request, Role $role, #[CurrentUser] User $actor): JsonResponse|RedirectResponse
    {
        $by = Actor::user($actor);
        $name = (string) $role->getAttribute('name');

        try {
            $members = $this->deletion->delete($by, $role);
        } catch (BuiltInRoleImmutable $e) {
            $this->audit->record(AuditAction::RoleDeleted, $by, $role, ['role' => $name], AuditOutcome::Denied);

            return $this->refuse($request, 'built_in_role_immutable', 422, 'role', $e->getMessage());
        } catch (PrivilegeEscalation $e) {
            $this->audit->record(AuditAction::RoleDeleted, $by, $role, ['role' => $name, 'refused' => Permission::values($e->permissions)], AuditOutcome::Denied);

            return $this->refuse($request, 'privilege_escalation', 403, 'role', $e->getMessage(), $e->permissions);
        }

        $this->audit->record(AuditAction::RoleDeleted, $by, null, ['role' => $name, 'members' => $members]);

        return to_route('roles.index');
    }

    /** @return array<string, list<mixed>> */
    private function permissionRules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permission::values(Permission::assignableToUsers()))],
        ];
    }

    /** A name no role has yet (compared without case) and no built-in name. */
    private function newRoleName(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $name = trim((string) $value);
            if (Roles::isBuiltIn($name) || Role::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
                $fail('validation.role.taken')->translate();
            }
        };
    }

    /**
     * @param  list<Permission>  $permissions
     * @return array<string, Permission>
     */
    private static function keyed(array $permissions): array
    {
        return array_combine(Permission::values($permissions), $permissions);
    }
}
