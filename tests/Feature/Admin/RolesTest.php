<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Authorization\Permission;
use App\Authorization\Roles;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/** A custom role whose permissions the operator can change. */
function customRole(string $name, array $permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);
    $role->syncPermissions(array_map(fn (Permission $p) => $p->value, $permissions));

    return $role;
}

/** A user with roles.manage but not the whole catalogue (a "co-maintainer"). */
function coMaintainer(): User
{
    customRole('co-maintainer', [Permission::RolesManage, Permission::CatalogBrowse, Permission::AuditView]);

    return User::factory()->withRole('co-maintainer')->create();
}

it('seeds the built-in roles with their permissions', function () {
    expect(Role::findByName(Roles::OPERATOR, 'web')->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::assignableToUsers())->map->value->sort()->values()->all())
        ->and(Role::findByName(Roles::USER, 'web')->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['catalog.browse', 'tokens.manage_own']);
});

it('refuses to change the permissions of the built-in operator role even with roles.manage', function () {
    $operator = User::factory()->operator()->create();

    $this->actingAs($operator)
        ->putJson('/roles/'.Role::findByName(Roles::OPERATOR, 'web')->id, ['permissions' => ['catalog.browse']])
        ->assertStatus(422)
        ->assertJsonPath('error', 'built_in_role_immutable');

    expect(Role::findByName(Roles::OPERATOR, 'web')->permissions)->toHaveCount(count(Permission::assignableToUsers()));
});

it('refuses to change the permissions of the built-in user role', function () {
    $operator = User::factory()->operator()->create();

    $this->actingAs($operator)
        ->putJson('/roles/'.Role::findByName(Roles::USER, 'web')->id, ['permissions' => ['audit.view']])
        ->assertStatus(422)
        ->assertJsonPath('error', 'built_in_role_immutable');
});

it('refuses to delete a built-in role', function () {
    $operator = User::factory()->operator()->create();

    $this->actingAs($operator)
        ->deleteJson('/roles/'.Role::findByName(Roles::USER, 'web')->id)
        ->assertStatus(422)
        ->assertJsonPath('error', 'built_in_role_immutable');
});

it('creates a custom role with permissions the actor holds and logs it', function () {
    $operator = User::factory()->operator()->create();

    $this->actingAs($operator)
        ->post('/roles', ['name' => 'co-maintainer', 'permissions' => ['audit.view', 'catalog.browse']])
        ->assertRedirect('/roles');

    expect(Role::findByName('co-maintainer', 'web')->permissions->pluck('name')->sort()->values()->all())->toBe(['audit.view', 'catalog.browse'])
        ->and(AuditEntry::query()->where('action', 'role.create')->count())->toBe(1);
});

it('refuses a role name of a built-in role', function () {
    $operator = User::factory()->operator()->create();

    $this->actingAs($operator)
        ->postJson('/roles', ['name' => 'Operator', 'permissions' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('refuses to grant a permission the actor lacks through a custom role', function () {
    $actor = coMaintainer();
    $role = customRole('helper', [Permission::CatalogBrowse]);

    $this->actingAs($actor)
        ->putJson('/roles/'.$role->id, ['permissions' => ['catalog.browse', 'users.manage']])
        ->assertStatus(403)
        ->assertJsonPath('error', 'privilege_escalation')
        ->assertJsonPath('permissions', ['users.manage']);

    expect($role->fresh()?->permissions->pluck('name')->all())->toBe(['catalog.browse'])
        ->and(AuditEntry::query()->where('action', 'role.update')->where('outcome', 'denied')->count())->toBe(1);
});

it('refuses satellite.execute in a custom role', function () {
    $operator = User::factory()->operator()->create();

    $this->actingAs($operator)
        ->postJson('/roles', ['name' => 'robots', 'permissions' => ['satellite.execute']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions.0');
});

it('changes the permissions of a custom role and logs the difference', function () {
    $operator = User::factory()->operator()->create();
    $role = customRole('helper', [Permission::CatalogBrowse]);

    $this->actingAs($operator)
        ->put('/roles/'.$role->id, ['permissions' => ['audit.view']])
        ->assertRedirect('/roles');

    $entry = AuditEntry::query()->where('action', 'role.update')->sole();
    expect($role->fresh()?->permissions->pluck('name')->all())->toBe(['audit.view'])
        ->and($entry->context)->toBe(['role' => 'helper', 'added' => ['audit.view'], 'removed' => ['catalog.browse']]);
});

it('moves the members of a deleted custom role to the user role', function () {
    $operator = User::factory()->operator()->create();
    $role = customRole('helper', [Permission::AuditView]);
    $member = User::factory()->withRole('helper')->create();

    $this->actingAs($operator)->delete('/roles/'.$role->id)->assertRedirect('/roles');

    expect(Role::query()->where('name', 'helper')->exists())->toBeFalse()
        ->and($member->fresh()?->getRoleNames()->all())->toBe([Roles::USER])
        ->and(AuditEntry::query()->where('action', 'role.delete')->count())->toBe(1);
});

it('shows the catalogue, the roles and which permissions the actor may grant', function () {
    $actor = coMaintainer();

    $this->actingAs($actor)->get('/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/Roles')
        ->has('roles', 3)
        ->where('roles.0.name', Roles::OPERATOR)
        ->where('roles.0.built_in', true)
        ->where('grantable', ['roles.manage', 'catalog.browse', 'audit.view'])
        ->has('groups'));
});

it('lists satellite.execute nowhere on the page', function () {
    $operator = User::factory()->operator()->create();

    $this->actingAs($operator)->get('/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('groups', fn ($groups) => ! str_contains((string) json_encode($groups), 'satellite.execute')));
});
