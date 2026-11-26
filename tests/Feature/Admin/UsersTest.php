<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Authorization\Permission;
use App\Authorization\Roles;
use App\Identity\Identity;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

it('shows provider email and account email as separate, labelled fields', function () {
    $operator = User::factory()->operator()->create(['username' => 'alice']);
    $friend = User::factory()->withRole(Roles::USER)->create(['username' => 'bob', 'email' => 'bob@example.org']);
    Identity::create([
        'user_id' => $friend->id, 'method' => 'oidc', 'provider_key' => 'example', 'subject' => 's-1', 'email' => 'bob@idp.example.net',
    ]);

    $this->actingAs($operator)->get('/users')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/Users')
        ->where('users.1.username', 'bob')
        ->where('users.1.contact_email', 'bob@example.org')
        ->where('users.1.identities.0.provider_email', 'bob@idp.example.net')
        ->where('users.1.identities.0.method', 'oidc')
        ->missing('users.1.email')
        ->missing('users.1.identities.0.email'));

    $page = (string) file_get_contents(resource_path('js/pages/admin/Users.svelte'));
    expect($page)->toContain("t('users.contact_email')")->toContain("t('users.provider_email')");
});

it('changes the role of a user, logs it and names both roles', function () {
    $operator = User::factory()->operator()->create();
    $friend = User::factory()->withRole(Roles::USER)->create();
    Role::create(['name' => 'helper', 'guard_name' => 'web'])->syncPermissions(['audit.view']);

    $this->actingAs($operator)->put("/users/{$friend->id}/role", ['role' => 'helper'])->assertRedirect('/users');

    $entry = AuditEntry::query()->where('action', 'role.assign')->sole();
    expect($friend->fresh()?->getRoleNames()->all())->toBe(['helper'])
        ->and($entry->subject_id)->toBe($friend->id)
        ->and($entry->context)->toBe(['role' => 'helper', 'previous' => [Roles::USER]]);
});

it('refuses to assign a role whose permissions the actor lacks', function () {
    Role::create(['name' => 'people', 'guard_name' => 'web'])->syncPermissions(['users.view', 'users.manage']);
    $actor = User::factory()->withRole('people')->create();
    $friend = User::factory()->withRole(Roles::USER)->create();

    $this->actingAs($actor)->putJson("/users/{$friend->id}/role", ['role' => Roles::OPERATOR])
        ->assertStatus(403)
        ->assertJsonPath('error', 'privilege_escalation');

    expect($friend->fresh()?->getRoleNames()->all())->toBe([Roles::USER]);
});

it('refuses to demote the last active operator from the web interface', function () {
    $operator = User::factory()->operator()->create();

    $this->actingAs($operator)->putJson("/users/{$operator->id}/role", ['role' => Roles::USER])
        ->assertStatus(409)
        ->assertJsonPath('error', 'last_operator');

    expect($operator->fresh()?->hasRole(Roles::OPERATOR))->toBeTrue();
});

it('locks and unlocks a user and logs both', function () {
    $operator = User::factory()->operator()->create();
    $friend = User::factory()->withRole(Roles::USER)->create();

    $this->actingAs($operator)->post("/users/{$friend->id}/lock")->assertRedirect('/users');
    expect($friend->fresh()?->locked_at)->not->toBeNull();

    $this->actingAs($operator)->delete("/users/{$friend->id}/lock")->assertRedirect('/users');
    expect($friend->fresh()?->locked_at)->toBeNull()
        ->and(AuditEntry::query()->whereIn('action', ['user.lock', 'user.unlock'])->count())->toBe(2);
});

it('refuses to lock the own account', function () {
    $operator = User::factory()->operator()->create();
    User::factory()->operator()->create();

    $this->actingAs($operator)->postJson("/users/{$operator->id}/lock")
        ->assertStatus(422)
        ->assertJsonPath('error', 'cannot_lock_self');
});

it('refuses to lock an account with more permissions than the actor', function () {
    Role::create(['name' => 'people', 'guard_name' => 'web'])->syncPermissions(['users.view', 'users.manage']);
    $actor = User::factory()->withRole('people')->create();
    $operator = User::factory()->operator()->create();

    $this->actingAs($actor)->postJson("/users/{$operator->id}/lock")
        ->assertStatus(403)
        ->assertJsonPath('error', 'privilege_escalation');
});

it('signs a locked user out on the next request', function () {
    $friend = User::factory()->withRole(Roles::USER)->create();
    $this->actingAs($friend)->get('/shared')->assertOk();

    User::query()->whereKey($friend->id)->update(['locked_at' => now()]);

    $this->get('/shared')->assertRedirect('/login');
    $this->assertGuest('web');
});

it('gives a friend the friend navigation and an operator the operator one', function (string $role, string $audience, array $has, array $lacks) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)->get($audience === 'friend' ? '/shared' : '/dashboard')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('audience', $audience)
            ->where('permissions', fn ($granted) => collect($has)->every(fn ($p) => collect($granted)->contains($p))
                && collect($lacks)->every(fn ($p) => ! collect($granted)->contains($p))));
})->with([
    'friend' => ['user', 'friend', ['catalog.browse', 'tokens.manage_own'], ['users.view', 'audit.view']],
    'operator' => ['operator', 'operator', ['users.view', 'audit.view', 'roles.manage'], ['satellite.execute']],
]);

it('sends a friend from the operator start page to their own', function () {
    $friend = User::factory()->withRole(Roles::USER)->create();

    $this->actingAs($friend)->get('/dashboard')->assertRedirect('/shared');
});

it('gives the release notice to operators only', function () {
    $friend = User::factory()->withRole(Roles::USER)->create();

    config(['app.version' => '0.1.0']);

    $this->actingAs($friend)->get('/shared', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '0.1.0',
        'X-Inertia-Partial-Component' => 'friend/Home',
        'X-Inertia-Partial-Data' => 'release',
    ])->assertOk()->assertJsonPath('props.release', null);

    expect(Permission::administrative())->not->toContain(Permission::CatalogBrowse);
});

it('answers a refused change of an interface form with the message on the field', function () {
    $operator = User::factory()->operator()->create();

    $this->actingAs($operator)->from('/users')
        ->put("/users/{$operator->id}/role", ['role' => Roles::USER])
        ->assertRedirect('/users')
        ->assertSessionHasErrors('role');
});

it('lists the sign-in identities of an account through its relation', function () {
    $user = User::factory()->create();
    $identity = Identity::create(['user_id' => $user->id, 'method' => 'plex', 'provider_key' => 'plex', 'subject' => '42']);

    expect($user->identities()->pluck('subject')->all())->toBe(['42'])
        ->and($identity->user->is($user))->toBeTrue();
});
