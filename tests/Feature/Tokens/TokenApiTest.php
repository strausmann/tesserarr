<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Authorization\Permission;
use App\Authorization\Roles;
use App\Models\User;
use App\Tokens\TokenIssuer;
use App\Tokens\TokenRevoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;

/*
| A token on the token path (/api/v1): the Authorizer decides with the
| token's abilities and the owner's permissions of now (Spec 9.5, "no token
| can do more than its creator", also after a demotion). Revoked, expired and
| locked tokens get no further.
*/

beforeEach(function () {
    // Stands in for the invitation route of task 10 (and any route that asks
    // a permission): the same group and the same check as a real one.
    Route::middleware('token-api')->prefix('api/v1')->group(function () {
        Route::post('invitations', fn () => response()->json(['ok' => true], 201))->middleware('can:invitations.create');
        Route::get('_probe/catalog', fn () => response()->json(['ok' => true]))->middleware('can:catalog.browse');
    });
    Route::getRoutes()->refreshNameLookups();

    $this->operator = User::factory()->operator()->create(['username' => 'alex']);
    // A second operator, so the first can be demoted.
    User::factory()->operator()->create();
});

function plainToken(User $owner, array $permissions, ?CarbonImmutable $expiresAt = null): string
{
    return app(TokenIssuer::class)->issue($owner, Actor::user($owner), 'test', $permissions, $expiresAt)->plainTextToken;
}

it('denies an operator token carrying only catalog.browse on POST /api/v1/invitations', function () {
    $plain = plainToken($this->operator, [Permission::CatalogBrowse]);

    $this->withToken($plain)->postJson('/api/v1/invitations')
        ->assertForbidden()
        ->assertJsonPath('message', 'token_lacks_permission');

    auth()->forgetGuards();
    $this->withToken(plainToken($this->operator, [Permission::InvitationsCreate]))
        ->postJson('/api/v1/invitations')->assertCreated();
});

it('denies a token after its owner was demoted', function () {
    $plain = plainToken($this->operator, [Permission::InvitationsCreate]);
    $this->withToken($plain)->postJson('/api/v1/invitations')->assertCreated();

    $this->operator->syncRoles([Roles::USER]);
    auth()->forgetGuards();

    $this->withToken($plain)->postJson('/api/v1/invitations')
        ->assertForbidden()
        ->assertJsonPath('message', 'owner_lacks_permission');
});

it('stores tokens hashed, records last use, stops after revocation and after expiry', function () {
    $new = app(TokenIssuer::class)->issue($this->operator, Actor::user($this->operator), 'script', [Permission::CatalogBrowse], CarbonImmutable::now()->addDay());
    [, $secret] = explode('|', $new->plainTextToken, 2);

    expect(PersonalAccessToken::query()->where('token', $secret)->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->where('token', hash('sha256', $secret))->exists())->toBeTrue()
        ->and($new->accessToken->fresh()?->last_used_at)->toBeNull();

    $this->travelTo(CarbonImmutable::now()->addMinutes(5));
    $this->withToken($new->plainTextToken)->getJson('/api/v1/_probe/catalog')->assertOk();
    expect($new->accessToken->fresh()?->last_used_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String());

    // Expired: one day and a minute later.
    $this->travelTo(CarbonImmutable::now()->addDay());
    auth()->forgetGuards();
    $this->withToken($new->plainTextToken)->getJson('/api/v1/_probe/catalog')->assertUnauthorized();
    $this->travelBack();

    // Revoked.
    $other = app(TokenIssuer::class)->issue($this->operator, Actor::user($this->operator), 'other', [Permission::CatalogBrowse]);
    auth()->forgetGuards();
    $this->withToken($other->plainTextToken)->getJson('/api/v1/_probe/catalog')->assertOk();
    app(TokenRevoker::class)->revoke($other->accessToken, Actor::user($this->operator));
    auth()->forgetGuards();
    $this->withToken($other->plainTextToken)->getJson('/api/v1/_probe/catalog')->assertUnauthorized();
});

it('does not accept a locked user’s token', function () {
    $friend = User::factory()->withRole(Roles::USER)->create();
    $plain = plainToken($friend, [Permission::CatalogBrowse]);
    $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

    $friend->forceFill(['locked_at' => now()])->save();
    auth()->forgetGuards();

    // Not even the self-service route, which asks no permission (ADR-0008 point 5).
    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
    auth()->forgetGuards();
    $this->withToken($plain)->getJson('/api/v1/_probe/catalog')->assertUnauthorized();
});

it('never lets a wildcard token through', function () {
    // Sanctum's default for createToken without abilities.
    $plain = $this->operator->createToken('wild')->plainTextToken;

    $this->withToken($plain)->getJson('/api/v1/_probe/catalog')
        ->assertForbidden()
        ->assertJsonPath('message', 'token_lacks_permission');
});

it('refuses a forged or malformed bearer', function (string $bearer) {
    $this->withToken($bearer)->getJson('/api/v1/me')->assertUnauthorized();
})->with([
    'no secret' => '1|',
    'wrong secret' => '1|tsr_not-the-secret',
    'not a token' => 'Bearer',
    'very long' => str_repeat('a', 5000),
]);

it('names the owner and what the token may do now on GET /api/v1/me', function () {
    $plain = plainToken($this->operator, [Permission::CatalogBrowse, Permission::InvitationsCreate]);

    $this->withToken($plain)->getJson('/api/v1/me')
        ->assertOk()
        ->assertExactJson([
            'id' => $this->operator->id,
            'username' => 'alex',
            'display_name' => $this->operator->display_name,
            'permissions' => ['invitations.create', 'catalog.browse'],
        ]);

    $this->operator->syncRoles([Roles::USER]);
    auth()->forgetGuards();

    $this->withToken($plain)->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('permissions', ['catalog.browse']);
});

it('names the account and its permissions on GET /api/v1/app/me', function () {
    $friend = User::factory()->withRole(Roles::USER)->create(['username' => 'sam']);

    $this->actingAs($friend)->withHeader('X-Requested-With', 'tesserarr')
        ->getJson('/api/v1/app/me')
        ->assertOk()
        ->assertExactJson([
            'id' => $friend->id,
            'username' => 'sam',
            'display_name' => $friend->display_name,
            'permissions' => ['tokens.manage_own', 'catalog.browse'],
        ]);
});
