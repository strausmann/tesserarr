<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Authorization\LastOperatorProtected;
use App\Authorization\Permission;
use App\Authorization\PrivilegeEscalation;
use App\Authorization\RoleGuard;
use App\Authorization\Roles;
use App\Http\Controllers\Controller;
use App\Identity\Identity;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * The account list of the operator (Users.dc.html): who has which role, how
 * they sign in, and the actions on an account (role, lock). Invitations join
 * the page with task 10, second factors with task 12.
 *
 * Every change goes through RoleGuard: the actor needs every permission of
 * the role they give and of the account they change, and at least one active
 * operator remains.
 */
final class UserController extends Controller
{
    use RefusesGuardedChanges;

    public function __construct(
        private readonly RoleGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function index(#[CurrentUser] User $actor): Response
    {
        $users = User::query()
            ->with(['roles:id,name', 'identities'])
            ->orderBy('username')
            ->get();

        return Inertia::render('admin/Users', [
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'username' => $user->username,
                'display_name' => $user->display_name,
                // The contact address of the account, labelled as such; the
                // addresses of the sign-in providers are listed per identity.
                'contact_email' => $user->email,
                'locked_at' => $user->locked_at?->toIso8601String(),
                'role' => $user->roles->first()?->getAttribute('name'),
                'identities' => $user->identities->map(fn (Identity $identity) => [
                    'method' => $identity->method,
                    'provider_key' => $identity->provider_key,
                    'provider_email' => $identity->email,
                ])->values()->all(),
                'self' => $user->id === $actor->id,
            ])->values()->all(),
            // Whether the actions show is read from the shared prop `permissions`.
            'roles' => Role::query()->where('guard_name', Roles::GUARD)->orderBy('id')->pluck('name')->all(),
        ]);
    }

    public function updateRole(Request $request, User $user, #[CurrentUser] User $actor): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', Roles::GUARD)],
        ]);
        /** @var Role $role */
        $role = Role::findByName((string) $validated['role'], Roles::GUARD);
        $previous = $user->getRoleNames()->values()->all();
        $by = Actor::user($actor);

        try {
            // Giving a role needs its permissions; taking one away needs the
            // permissions the account holds now.
            $this->guard->assertCanGrant($by, RoleGuard::permissionsOfRole($role));
            $this->guard->assertCanGrant($by, $user->grantedPermissions());
            $this->guard->assertKeepsAnOperator(fn () => $user->syncRoles([$role]));
        } catch (PrivilegeEscalation $e) {
            $this->audit->record(AuditAction::RoleAssigned, $by, $user, ['role' => $role->name, 'refused' => Permission::values($e->permissions)], AuditOutcome::Denied);

            return $this->refuse($request, 'privilege_escalation', 403, 'role', $e->getMessage(), $e->permissions);
        } catch (LastOperatorProtected $e) {
            $this->audit->record(AuditAction::RoleAssigned, $by, $user, ['role' => $role->name], AuditOutcome::Denied);

            return $this->refuse($request, 'last_operator', 409, 'role', $e->getMessage());
        }

        $this->audit->record(AuditAction::RoleAssigned, $by, $user, ['role' => $role->name, 'previous' => $previous]);

        return to_route('users.index');
    }

    public function lock(Request $request, User $user, #[CurrentUser] User $actor): JsonResponse|RedirectResponse
    {
        if ($user->id === $actor->id) {
            return $this->refuse($request, 'cannot_lock_self', 422, 'lock', __('users.cannot_lock_self'));
        }

        return $this->changeLock($request, $user, $actor, true);
    }

    public function unlock(Request $request, User $user, #[CurrentUser] User $actor): JsonResponse|RedirectResponse
    {
        return $this->changeLock($request, $user, $actor, false);
    }

    private function changeLock(Request $request, User $user, User $actor, bool $locked): JsonResponse|RedirectResponse
    {
        $by = Actor::user($actor);
        $action = $locked ? AuditAction::UserLocked : AuditAction::UserUnlocked;

        try {
            // Nobody locks (or unlocks) an account that can do more than they can.
            $this->guard->assertCanGrant($by, $user->grantedPermissions());
            if ($locked) {
                $this->guard->assertKeepsAnOperator(fn () => $user->forceFill(['locked_at' => now()])->save());
            } else {
                // Unlocking never lowers the number of active operators.
                $user->forceFill(['locked_at' => null])->save();
            }
        } catch (PrivilegeEscalation $e) {
            $this->audit->record($action, $by, $user, ['refused' => Permission::values($e->permissions)], AuditOutcome::Denied);

            return $this->refuse($request, 'privilege_escalation', 403, 'lock', $e->getMessage(), $e->permissions);
        } catch (LastOperatorProtected $e) {
            $this->audit->record($action, $by, $user, [], AuditOutcome::Denied);

            return $this->refuse($request, 'last_operator', 409, 'lock', $e->getMessage());
        }

        $this->audit->record($action, $by, $user);

        return to_route('users.index');
    }
}
