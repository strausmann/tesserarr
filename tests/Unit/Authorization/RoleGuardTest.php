<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Authorization\BuiltInRoleImmutable;
use App\Authorization\LastOperatorProtected;
use App\Authorization\Permission;
use App\Authorization\PrivilegeEscalation;
use App\Authorization\RoleGuard;
use App\Authorization\Roles;
use App\Models\User;
use Spatie\Permission\Models\Role;

/** The guard of the last active operator: the four changes it covers. */
function lastOperatorChange(string $op, User $operator): Closure
{
    return match ($op) {
        'demote' => fn () => $operator->syncRoles([Roles::USER]),
        'remove' => fn () => $operator->removeRole(Roles::OPERATOR),
        'delete' => fn () => $operator->delete(),
        'lock' => fn () => $operator->forceFill(['locked_at' => now()])->save(),
    };
}

it('refuses to demote, remove, delete or lock the last active operator', function (string $op) {
    $operator = User::factory()->operator()->create();

    app(RoleGuard::class)->assertKeepsAnOperator(lastOperatorChange($op, $operator));
})->with(['demote', 'remove', 'delete', 'lock'])->throws(LastOperatorProtected::class);

it('rolls the refused change back', function (string $op) {
    $operator = User::factory()->operator()->create();

    try {
        app(RoleGuard::class)->assertKeepsAnOperator(lastOperatorChange($op, $operator));
    } catch (LastOperatorProtected) {
    }

    $fresh = User::query()->find($operator->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh?->locked_at)->toBeNull()
        ->and($fresh?->hasRole(Roles::OPERATOR))->toBeTrue();
})->with(['demote', 'remove', 'delete', 'lock']);

it('counts a locked operator as inactive', function () {
    $active = User::factory()->operator()->create();
    User::factory()->operator()->create(['locked_at' => now()]);

    expect(fn () => app(RoleGuard::class)->assertKeepsAnOperator(fn () => $active->syncRoles([Roles::USER])))
        ->toThrow(LastOperatorProtected::class);
});

it('allows demoting an operator when a second active one exists', function () {
    $first = User::factory()->operator()->create();
    User::factory()->operator()->create();

    app(RoleGuard::class)->assertKeepsAnOperator(fn () => $first->syncRoles([Roles::USER]));

    expect($first->fresh()?->hasRole(Roles::OPERATOR))->toBeFalse();
});

it('returns what the change returns', function () {
    User::factory()->operator()->count(2)->create();

    expect(app(RoleGuard::class)->assertKeepsAnOperator(fn () => 'changed'))->toBe('changed');
});

it('refuses to grant a permission the actor lacks', function () {
    $userWithoutAudit = User::factory()->withRole(Roles::USER)->create();

    app(RoleGuard::class)->assertCanGrant(Actor::user($userWithoutAudit), [Permission::AuditView]);
})->throws(PrivilegeEscalation::class, 'audit.view');

it('lets a user grant permissions they hold', function () {
    $operator = User::factory()->operator()->create();

    app(RoleGuard::class)->assertCanGrant(Actor::user($operator), [Permission::AuditView, Permission::CatalogBrowse]);

    expect(true)->toBeTrue();
});

it('refuses satellite.execute to any user, operators included', function () {
    $operator = User::factory()->operator()->create();

    app(RoleGuard::class)->assertCanGrant(Actor::user($operator), [Permission::SatelliteExecute]);
})->throws(PrivilegeEscalation::class, 'satellite.execute');

it('refuses to grant from a locked account', function () {
    $operator = User::factory()->operator()->create(['locked_at' => now()]);

    app(RoleGuard::class)->assertCanGrant(Actor::user($operator), [Permission::CatalogBrowse]);
})->throws(PrivilegeEscalation::class);

it('lets the console grant every permission assignable to users', function () {
    app(RoleGuard::class)->assertCanGrant(Actor::cli('ops'), Permission::assignableToUsers());

    expect(true)->toBeTrue();
});

it('refuses satellite.execute from the console', function () {
    app(RoleGuard::class)->assertCanGrant(Actor::cli('ops'), [Permission::SatelliteExecute]);
})->throws(PrivilegeEscalation::class, 'satellite.execute');

it('limits the console to the permissions of the owner it grants for', function () {
    $owner = User::factory()->withRole(Roles::USER)->create();

    app(RoleGuard::class)->assertCanGrant(Actor::cli('ops'), [Permission::AuditView], $owner);
})->throws(PrivilegeEscalation::class, 'audit.view');

it('refuses every grant from a satellite', function () {
    app(RoleGuard::class)->assertCanGrant(Actor::satellite(3, 'cellar'), [Permission::CatalogBrowse]);
})->throws(PrivilegeEscalation::class);

it('refuses to change the permissions of a built-in role', function (string $role) {
    $operator = User::factory()->operator()->create();

    app(RoleGuard::class)->assertMayChangePermissions(Actor::user($operator), Role::findByName($role, 'web'));
})->with(['operator', 'user'])->throws(BuiltInRoleImmutable::class);

it('lets a change through when the installation has no active operator yet', function () {
    $first = User::factory()->withRole(Roles::USER)->create();

    app(RoleGuard::class)->assertKeepsAnOperator(fn () => $first->syncRoles([Roles::USER]));
    app(RoleGuard::class)->assertKeepsAnOperator(fn () => $first->syncRoles([Roles::OPERATOR]));

    expect($first->fresh()?->hasRole(Roles::OPERATOR))->toBeTrue();
});

it('names what the actor may grant, in catalogue order', function () {
    $user = User::factory()->withRole(Roles::USER)->create();

    expect(app(RoleGuard::class)->grantableBy(Actor::user($user)))->toBe([Permission::TokensManageOwn, Permission::CatalogBrowse])
        ->and(app(RoleGuard::class)->grantableBy(Actor::cli('ops'), $user))->toBe([Permission::TokensManageOwn, Permission::CatalogBrowse])
        ->and(app(RoleGuard::class)->grantableBy(Actor::satellite(1, 'cellar')))->toBe([]);
});
