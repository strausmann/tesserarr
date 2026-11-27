<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditOutcome;
use App\Authorization\Roles;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
| The token pages (Tokens.dc.html): /tokens lists the own tokens, and every
| token for an account with tokens.manage_all. Issuing goes through the
| session API behind a fresh password confirmation (K13) and answers with
| the token once; afterwards it is nowhere: not in a page, the session, the
| log or the audit log.
*/

beforeEach(function () {
    $this->operator = User::factory()->operator()->create(['username' => 'alex']);
    $this->friend = User::factory()->withRole(Roles::USER)->create(['username' => 'sam']);
});

/** A signed-in request on the session path, with a fresh password confirmation. */
function asApp(User $user, bool $confirmed = true): TestCase
{
    $request = test()->actingAs($user, 'web')->withHeader('X-Requested-With', 'tesserarr');

    return $confirmed ? $request->withSession(['auth.password_confirmed_at' => time()]) : $request;
}

function issueThroughApp(User $user, array $body): TestResponse
{
    return asApp($user)->postJson('/api/v1/app/tokens', $body);
}

/** The decoded payloads of the stored sessions (database driver). */
function tokenTestSessions(): string
{
    return DB::table('sessions')->pluck('payload')
        ->map(fn ($payload) => (string) base64_decode((string) $payload, true))->implode("\n");
}

it('asks for the password again before the token page', function () {
    $this->actingAs($this->friend, 'web')->get('/tokens')->assertRedirect('/user/confirm-password');
});

it('asks for the password again before a token is issued', function () {
    asApp($this->friend, confirmed: false)
        ->postJson('/api/v1/app/tokens', ['name' => 'script', 'permissions' => ['catalog.browse'], 'expires' => '90'])
        ->assertStatus(423);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('shows a friend only their own tokens, without the token itself', function () {
    $this->friend->createToken('mine', ['catalog.browse']);
    $this->operator->createToken('not mine', ['status.view']);

    $this->actingAs($this->friend, 'web')->withSession(['auth.password_confirmed_at' => time()])->get('/tokens')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/Tokens')
            ->has('tokens', 1)
            ->where('tokens.0.name', 'mine')
            ->where('tokens.0.permissions', ['catalog.browse'])
            ->missing('tokens.0.token')
            ->where('can.issue', true)
            ->where('grantable', ['tokens.manage_own', 'catalog.browse'])
            ->has('presets', 2));
});

it('shows every token with its owner to an account with tokens.manage_all', function () {
    $this->friend->createToken('sam script', ['catalog.browse']);
    $this->operator->createToken('bot', ['invitations.create']);

    $this->actingAs($this->operator, 'web')->withSession(['auth.password_confirmed_at' => time()])->get('/tokens')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Tokens')
            ->has('tokens', 2)
            // The newest first.
            ->where('tokens.0.owner.username', 'alex')
            ->where('tokens.0.mine', true)
            ->where('tokens.1.owner.username', 'sam')
            ->where('tokens.1.mine', false)
            ->missing('tokens.1.token'));
});

it('issues a token once and keeps it out of everything afterwards', function () {
    config(['session.driver' => 'database', 'session.encrypt' => false]);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    $response = issueThroughApp($this->friend, ['name' => 'Katalog-Export', 'permissions' => ['catalog.browse'], 'expires' => '30'])
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('token.name', 'Katalog-Export')
        ->assertJsonPath('token.permissions', ['catalog.browse']);

    $plain = (string) $response->json('plain_text');
    [, $secret] = explode('|', $plain, 2);
    expect($secret)->toStartWith('tsr_')
        ->and(strlen($secret))->toBeGreaterThan(40);

    // It works on the token path.
    auth()->forgetGuards();
    $this->withToken($plain)->getJson('/api/v1/me')->assertOk()->assertJsonPath('username', 'sam');

    // And is shown nowhere again.
    $page = $this->actingAs($this->friend, 'web')->withSession(['auth.password_confirmed_at' => time()])->get('/tokens')->assertOk();
    $entries = AuditEntry::query()->get()->toJson();

    expect($page->getContent())->not->toContain($secret)
        ->and(tokenTestSessions())->not->toContain($secret)
        ->and(tokenTestSessions())->not->toContain(hash('sha256', $secret))
        ->and(implode("\n", $logged))->not->toContain($secret)
        ->and($entries)->not->toContain($secret)
        ->and(PersonalAccessToken::query()->sole()->toJson())->not->toContain($secret)
        ->and(PersonalAccessToken::query()->sole()->toJson())->not->toContain(hash('sha256', $secret));
});

it('expands a preset to its permissions', function () {
    issueThroughApp($this->friend, ['name' => 'Sonarr', 'preset' => 'arr', 'expires' => 'never'])
        ->assertCreated()
        ->assertJsonPath('token.permissions', ['catalog.browse'])
        ->assertJsonPath('token.expires_at', null);
});

it('sets the expiry the form names', function (string $choice, ?int $days) {
    $this->freezeTime();
    $response = issueThroughApp($this->operator, ['name' => 't', 'permissions' => ['status.view'], 'expires' => $choice])->assertCreated();

    $expires = PersonalAccessToken::query()->sole()->expires_at;
    expect($days === null ? $expires : $expires?->toIso8601String())
        ->toBe($days === null ? null : now()->addDays($days)->toIso8601String());
})->with([
    '30 days' => ['30', 30],
    '90 days' => ['90', 90],
    'a year' => ['365', 365],
    'never' => ['never', null],
]);

it('refuses an expiry, a permission or a preset that does not exist', function (array $body, string $field) {
    issueThroughApp($this->operator, ['name' => 't', 'expires' => '90', ...$body])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'expiry' => [['permissions' => ['status.view'], 'expires' => '7'], 'expires'],
    'permission' => [['permissions' => ['status.view', 'everything']], 'permissions.1'],
    'preset' => [['preset' => 'satellite'], 'preset'],
    'nothing' => [[], 'permissions'],
    'wildcard' => [['permissions' => ['*']], 'permissions.0'],
    'name' => [['name' => str_repeat('x', 101), 'permissions' => ['status.view']], 'name'],
]);

it('answers 403 privilege_escalation for a permission the account lacks, and logs the refusal', function () {
    issueThroughApp($this->friend, ['name' => 'admin', 'permissions' => ['catalog.browse', 'users.manage'], 'expires' => '30'])
        ->assertForbidden()
        ->assertJsonPath('message', 'privilege_escalation')
        ->assertJsonPath('refused', ['users.manage']);

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', AuditAction::TokenIssued->value)->sole()->outcome)->toBe(AuditOutcome::Denied);
});

it('refuses satellite.execute through the session API', function () {
    issueThroughApp($this->operator, ['name' => 's', 'permissions' => ['satellite.execute'], 'expires' => 'never'])
        ->assertForbidden()
        ->assertJsonPath('message', 'privilege_escalation');
});

it('revokes an own token and not another account’s', function () {
    $mine = $this->friend->createToken('mine', ['catalog.browse'])->accessToken;
    $theirs = $this->operator->createToken('theirs', ['status.view'])->accessToken;

    asApp($this->friend)->deleteJson("/api/v1/app/tokens/{$theirs->id}")->assertNotFound();
    expect(PersonalAccessToken::query()->find($theirs->id))->not->toBeNull();

    asApp($this->friend)->deleteJson("/api/v1/app/tokens/{$mine->id}")->assertNoContent();
    expect(PersonalAccessToken::query()->find($mine->id))->toBeNull()
        ->and(AuditEntry::query()->where('action', AuditAction::TokenRevoked->value)->sole()->actor_id)->toBe($this->friend->id);
});

it('lets tokens.manage_all revoke another account’s token, and nobody else', function () {
    $theirs = $this->friend->createToken('sam script', ['catalog.browse'])->accessToken;

    asApp($this->friend)->deleteJson("/api/v1/app/admin/tokens/{$theirs->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'owner_lacks_permission');

    asApp($this->operator)->deleteJson("/api/v1/app/admin/tokens/{$theirs->id}")->assertNoContent();
    expect(PersonalAccessToken::query()->find($theirs->id))->toBeNull();
});

it('answers 404 for a token that is gone', function () {
    asApp($this->operator)->deleteJson('/api/v1/app/admin/tokens/999999')->assertNotFound();
    asApp($this->friend)->deleteJson('/api/v1/app/tokens/999999')->assertNotFound();
});

it('does not let a token of the token path manage tokens', function () {
    $plain = $this->operator->createToken('manager', ['tokens.manage_own', 'tokens.manage_all'])->plainTextToken;

    // The token path has no token routes, and the session path takes no bearer.
    $this->withToken($plain)->postJson('/api/v1/tokens', ['name' => 'x', 'permissions' => ['status.view']])->assertNotFound();
    $this->withToken($plain)->withHeader('X-Requested-With', 'tesserarr')
        ->postJson('/api/v1/app/tokens', ['name' => 'x', 'permissions' => ['status.view']])->assertUnauthorized();
});

it('lists only the own tokens on the friend home, by name', function () {
    $this->friend->createToken('sonarr at home', ['catalog.browse']);
    $this->operator->createToken('not hers', ['status.view']);

    $this->actingAs($this->friend, 'web')->get('/shared')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('friend/Home')
            ->where('tokens.0.name', 'sonarr at home')
            ->where('tokens_total', 1)
            ->has('tokens', 1)
            ->missing('tokens.0.permissions'));
});

it('lets an account without tokens.manage_own still see and revoke its own tokens, but not issue', function () {
    $role = Role::create(['name' => 'readers', 'guard_name' => Roles::GUARD]);
    $role->givePermissionTo('catalog.browse');
    $reader = User::factory()->withRole('readers')->create();
    $token = $reader->createToken('left over', ['catalog.browse'])->accessToken;

    $this->actingAs($reader, 'web')->withSession(['auth.password_confirmed_at' => time()])->get('/tokens')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/Tokens')
            ->where('tokens.0.id', $token->id)
            ->where('can.issue', false));

    asApp($reader)->postJson('/api/v1/app/tokens', ['name' => 'x', 'permissions' => ['catalog.browse'], 'expires' => '30'])
        ->assertForbidden();
    asApp($reader)->deleteJson("/api/v1/app/tokens/{$token->id}")->assertNoContent();
    expect(PersonalAccessToken::query()->find($token->id))->toBeNull();
});

it('lets an account with only tokens.manage_all revoke its own token on the own path', function () {
    $role = Role::create(['name' => 'token-admins', 'guard_name' => Roles::GUARD]);
    $role->givePermissionTo('tokens.manage_all');
    $admin = User::factory()->withRole('token-admins')->create();
    $token = $admin->createToken('mine', ['tokens.manage_all'])->accessToken;

    asApp($admin)->deleteJson("/api/v1/app/tokens/{$token->id}")->assertNoContent();
});
