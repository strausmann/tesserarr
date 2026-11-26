<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Audit\AuditPresenter;
use App\Authorization\LastOperatorProtected;
use App\Authorization\Permission;
use App\Authorization\PrivilegeEscalation;
use App\Authorization\RoleGuard;
use App\Authorization\Roles;
use App\Http\Controllers\Controller;
use App\Identity\Identity;
use App\Models\User;
use App\Privacy\AccountEmailPolicy;
use App\Privacy\AccountEmailState;
use App\Privacy\EmailAlreadyClaimed;
use App\Privacy\EmailClaimThrottled;
use App\Privacy\EmailConfirmation;
use App\Privacy\EmailEntryDisabled;
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

    public const RECENT_ENTRIES = 10;

    public function __construct(
        private readonly RoleGuard $guard,
        private readonly AuditLogger $audit,
        private readonly EmailConfirmation $confirmation,
        private readonly AccountEmailPolicy $policy,
        private readonly AuditPresenter $presenter,
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
                'contact_email' => $user->confirmedEmail(),
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

    /**
     * One account (User-Detail.dc.html): role, sign-in methods with the
     * provider addresses, the account address with its confirmation state,
     * the latest log entries about it. Task 8a builds the contact section and
     * the entries; invitations (10), second factors (12) and grants (21a)
     * join with their tasks.
     */
    public function show(User $user, #[CurrentUser] User $actor): Response
    {
        $user->load(['roles:id,name', 'identities']);

        return Inertia::render('admin/UserDetail', [
            'account' => [
                'id' => $user->id,
                'username' => $user->username,
                'display_name' => $user->display_name,
                'role' => $user->roles->first()?->getAttribute('name'),
                'locked_at' => $user->locked_at?->toIso8601String(),
                'self' => $user->id === $actor->id,
            ],
            'email' => AccountEmailState::of($user, $this->policy),
            'identities' => $user->identities->map(fn (Identity $identity) => [
                'id' => $identity->id,
                'method' => $identity->method,
                'provider_key' => $identity->provider_key,
                'provider_email' => $identity->email,
            ])->values()->all(),
            'entries' => $this->presenter->present(
                AuditPresenter::involving($user)->orderByDesc('id')->limit(self::RECENT_ENTRIES)->get(),
            ),
        ]);
    }

    /**
     * The operator enters an account address for somebody (task 8a). It
     * counts once its owner opened the link; a confirmed old address stays
     * valid until then and is told about the change. Like every change of an
     * account, it needs the permissions the account holds.
     */
    public function updateEmail(Request $request, User $user, #[CurrentUser] User $actor): JsonResponse|RedirectResponse
    {
        $valid = $request->validate(['email' => ['required', 'string', 'max:254', 'email:rfc,strict']]);
        $by = Actor::user($actor);

        try {
            // The check runs under its locks in the transaction of the change.
            $this->confirmation->request($user, (string) $valid['email'], $by, function () use ($by, $actor, $user): void {
                $this->guard->lockAccounts([$user], [$actor]);
                $this->guard->lockPermissionsOf($user);
                $this->guard->assertCanGrant($by, $user->grantedPermissions());
            });
        } catch (PrivilegeEscalation $e) {
            return $this->refuse($request, 'privilege_escalation', 403, 'email', $e->getMessage(), $e->permissions);
        } catch (EmailEntryDisabled $e) {
            return $this->refuse($request, 'email_entry_disabled', 409, 'email', $e->getMessage());
        } catch (EmailAlreadyClaimed $e) {
            return $this->refuse($request, 'email_taken', 409, 'email', $e->getMessage());
        } catch (EmailClaimThrottled $e) {
            return $this->refuse($request, 'email_throttled', 429, 'email', $e->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.email.link_sent')]);

        return back();
    }

    /** Frees the reserved, unconfirmed address of an account at once (logged). */
    public function releaseEmail(Request $request, User $user, #[CurrentUser] User $actor): JsonResponse|RedirectResponse
    {
        $by = Actor::user($actor);

        try {
            $this->confirmation->release($user, $by, function () use ($by, $actor, $user): void {
                $this->guard->lockAccounts([$user], [$actor]);
                $this->guard->lockPermissionsOf($user);
                $this->guard->assertCanGrant($by, $user->grantedPermissions());
            });
        } catch (PrivilegeEscalation $e) {
            return $this->refuse($request, 'privilege_escalation', 403, 'email', $e->getMessage(), $e->permissions);
        }

        return back();
    }

    public function updateRole(Request $request, User $user, #[CurrentUser] User $actor): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', Roles::GUARD)],
        ]);
        /** @var Role $role */
        $role = Role::findByName((string) $validated['role'], Roles::GUARD);
        $by = Actor::user($actor);

        try {
            DB::transaction(function () use ($by, $actor, $user, $role): void {
                // Locks first (the accounts, then the role and the account's
                // permissions), then every check on what is locked, then the change.
                $this->guard->lockAccounts([$user], [$actor]);
                $previous = $user->unsetRelation('roles')->getRoleNames()->values()->all();
                $locked = $this->guard->lockRole($role, forUpdate: false);
                $this->guard->lockPermissionsOf($user);
                // Giving a role needs its permissions; taking one away needs
                // the permissions the account holds now.
                $this->guard->assertCanGrant($by, RoleGuard::permissionsOfRole($locked));
                $this->guard->assertCanGrant($by, $user->grantedPermissions());
                $this->guard->assertKeepsAnOperator(fn () => $user->syncRoles([$locked]));
                // In the transaction: no change without its entry.
                $this->audit->record(AuditAction::RoleAssigned, $by, $user, ['role' => $locked->name, 'previous' => $previous]);
            }, 3);
        } catch (PrivilegeEscalation $e) {
            $this->audit->record(AuditAction::RoleAssigned, $by, $user, ['role' => $role->name, 'refused' => Permission::values($e->permissions)], AuditOutcome::Denied);

            return $this->refuse($request, 'privilege_escalation', 403, 'role', $e->getMessage(), $e->permissions);
        } catch (LastOperatorProtected $e) {
            $this->audit->record(AuditAction::RoleAssigned, $by, $user, ['role' => $role->name], AuditOutcome::Denied);

            return $this->refuse($request, 'last_operator', 409, 'role', $e->getMessage());
        }

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
            DB::transaction(function () use ($by, $actor, $user, $locked, $action): void {
                $this->guard->lockAccounts([$user], [$actor]);
                $this->guard->lockPermissionsOf($user);
                // Nobody locks (or unlocks) an account that can do more than they can.
                $this->guard->assertCanGrant($by, $user->grantedPermissions());
                if ($locked) {
                    $this->guard->assertKeepsAnOperator(fn () => $user->forceFill(['locked_at' => now()])->save());
                } else {
                    // Unlocking never lowers the number of active operators.
                    $user->forceFill(['locked_at' => null])->save();
                }
                $this->audit->record($action, $by, $user);
            }, 3);
        } catch (PrivilegeEscalation $e) {
            $this->audit->record($action, $by, $user, ['refused' => Permission::values($e->permissions)], AuditOutcome::Denied);

            return $this->refuse($request, 'privilege_escalation', 403, 'lock', $e->getMessage(), $e->permissions);
        } catch (LastOperatorProtected $e) {
            $this->audit->record($action, $by, $user, [], AuditOutcome::Denied);

            return $this->refuse($request, 'last_operator', 409, 'lock', $e->getMessage());
        }

        return to_route('users.index');
    }
}
