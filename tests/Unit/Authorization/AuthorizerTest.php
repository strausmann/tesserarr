<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Authorization\Authorizer;
use App\Authorization\Permission;
use App\Authorization\Roles;
use App\Models\User;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

use function Tests\Support\tokenWith;

/*
| The single permission check (ADR-0016). Each test names the reason the
| decision must carry, so a counter-test that removes one condition turns the
| test red with that reason instead of being caught by another one.
*/

/** Runs $check inside a request to a route of the given middleware group, signed in through `web` as $user. */
function onRoute(string $group, ?User $user, Closure $check): mixed
{
    $result = null;
    Route::middleware($group)->get('/_authorizer/probe', function () use ($check, &$result) {
        $result = $check();

        return 'ok';
    });

    $test = test();
    if ($user !== null) {
        $test->actingAs($user, 'web');
    }
    $test->get('/_authorizer/probe')->assertOk();

    return $result;
}

it('allows an operator on a session route', function () {
    $operator = User::factory()->operator()->create();

    $decision = onRoute('web', $operator, fn () => app(Authorizer::class)->allows($operator, null, Permission::AuditView));

    expect($decision->allowed)->toBeTrue()->and($decision->reason)->toBe('ok');
});

it('denies when the owner lost the permission although the token still carries it', function () {
    $operator = User::factory()->operator()->create();
    $token = tokenWith([Permission::InvitationsCreate->value], $operator);

    $operator->syncRoles([Roles::USER]);

    $decision = app(Authorizer::class)->allows($operator, $token, Permission::InvitationsCreate);

    expect($decision->allowed)->toBeFalse()->and($decision->reason)->toBe('owner_lacks_permission');
});

it('denies a token without the ability although the owner has the permission', function () {
    $operator = User::factory()->operator()->create();

    $decision = app(Authorizer::class)->allows($operator, tokenWith([Permission::CatalogBrowse->value], $operator), Permission::InvitationsCreate);

    expect($decision->allowed)->toBeFalse()->and($decision->reason)->toBe('token_lacks_permission');
});

it('allows a token that carries the ability of an owner who has the permission', function () {
    $operator = User::factory()->operator()->create();

    $decision = app(Authorizer::class)->allows($operator, tokenWith([Permission::InvitationsCreate->value], $operator), Permission::InvitationsCreate);

    expect($decision->allowed)->toBeTrue()->and($decision->reason)->toBe('ok');
});

it('denies a token of another owner', function () {
    $operator = User::factory()->operator()->create();
    $other = User::factory()->operator()->create();

    $decision = app(Authorizer::class)->allows($operator, tokenWith([Permission::AuditView->value], $other), Permission::AuditView);

    expect($decision->allowed)->toBeFalse()->and($decision->reason)->toBe('token_missing');
});

it('denies a locked user even with a token that carries the ability', function () {
    $operator = User::factory()->operator()->create();
    $token = tokenWith([Permission::AuditView->value], $operator);
    User::query()->whereKey($operator->id)->update(['locked_at' => now()]);

    $decision = app(Authorizer::class)->allows($operator, $token, Permission::AuditView);

    expect($decision->allowed)->toBeFalse()->and($decision->reason)->toBe('locked');
});

it('denies a token-api route when no access token was resolved', function () {
    $operator = User::factory()->operator()->create();

    $decisionOnTokenRouteWithoutToken = onRoute('token-api', $operator, fn () => app(Authorizer::class)->allows($operator, null, Permission::AuditView));

    expect($decisionOnTokenRouteWithoutToken->allowed)->toBeFalse()
        ->and($decisionOnTokenRouteWithoutToken->reason)->toBe('token_missing');
});

it('denies a missing credential outside any route', function () {
    $operator = User::factory()->operator()->create();
    $this->actingAs($operator, 'web');

    expect(app(Authorizer::class)->allows($operator, null, Permission::AuditView)->reason)->toBe('token_missing');
});

it('treats a missing credential as a session only for the signed-in user', function () {
    $operator = User::factory()->operator()->create();
    $other = User::factory()->operator()->create();

    $decision = onRoute('web', $operator, fn () => app(Authorizer::class)->allows($other, null, Permission::AuditView));

    expect($decision->allowed)->toBeFalse()->and($decision->reason)->toBe('token_missing');
});

it('denies an owner that is no permission subject', function () {
    $owner = new GenericUser(['id' => 7]);

    expect(app(Authorizer::class)->allows($owner, null, Permission::AuditView)->reason)->toBe('owner_lacks_permission');
});

it('takes a role change into account without reloading the user model', function () {
    $user = User::factory()->withRole(Roles::USER)->create();
    $user->load('roles');

    onRoute('web', $user, function () use ($user) {
        expect(app(Authorizer::class)->allows($user, null, Permission::AuditView)->reason)->toBe('owner_lacks_permission');
        User::query()->findOrFail($user->id)->syncRoles([Roles::OPERATOR]);
        expect(app(Authorizer::class)->allows($user, null, Permission::AuditView)->reason)->toBe('ok');
    });
});

it('answers every gate of the catalogue through the Authorizer', function () {
    $user = User::factory()->withRole(Roles::USER)->create();

    onRoute('web', $user, function () use ($user) {
        foreach (Permission::cases() as $permission) {
            $expected = in_array($permission, [Permission::CatalogBrowse, Permission::TokensManageOwn], true);
            expect(Gate::forUser($user)->allows($permission->value))->toBe($expected, $permission->value);
        }
    });
});

it('names the reason of a denied gate in the response', function () {
    $user = User::factory()->withRole(Roles::USER)->create();

    $response = onRoute('web', $user, fn () => Gate::forUser($user)->inspect(Permission::AuditView->value));

    expect($response->allowed())->toBeFalse()->and($response->message())->toBe('owner_lacks_permission');
});

it('does not let the user model answer can() past the gate', function () {
    $user = User::factory()->withRole(Roles::USER)->create();
    $this->actingAs($user, 'web');

    // Outside a session route the Authorizer refuses; spatie's own check would have said yes.
    expect($user->can(Permission::CatalogBrowse->value))->toBeFalse();
});
