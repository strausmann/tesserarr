<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Audit\AuditEntry;
use App\Authorization\Permission;
use App\Authorization\Roles;
use App\Invitations\Invitation;
use App\Invitations\InvitationKind;
use App\Invitations\InvitationService;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
| Invitations over HTTP: the token path (POST/DELETE /api/v1/invitations, for
| scripts) and the session path of the interface (/api/v1/app/invitations,
| which also issues recovery invitations). The link is in the answer once.
*/

beforeEach(function () {
    $this->operator = User::factory()->operator()->create(['username' => 'alex']);
    User::factory()->operator()->create();
});

function bearerWith(User $owner, array $abilities): string
{
    return $owner->createToken('script', $abilities)->plainTextToken;
}

function appAs(User $user): TestCase
{
    return test()->actingAs($user, 'web')->withHeader('X-Requested-With', 'tesserarr');
}

it('creates an invitation with a token and answers its link once', function () {
    $this->freezeSecond();

    $response = $this->withToken(bearerWith($this->operator, ['invitations.create']))
        ->postJson('/api/v1/invitations', ['days' => 3, 'note' => 'For Sam'])
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private');

    $invitation = Invitation::query()->sole();
    $token = substr((string) $response->json('url'), strlen(url('/invite').'#'));

    expect($response->json())->toBe([
        'id' => $invitation->id,
        'url' => url('/invite').'#'.$token,
        'expires_at' => now()->addDays(3)->toIso8601String(),
    ])
        ->and(hash('sha256', $token))->toBe($invitation->token_hash)
        ->and($invitation->note)->toBe('For Sam')
        ->and($invitation->created_by_actor['type'])->toBe(Actor::TOKEN)
        ->and($invitation->created_by_actor['id'])->toBe($this->operator->id);
});

it('takes the instance default when days is left out and refuses a value out of range', function () {
    $this->freezeSecond();
    $plain = bearerWith($this->operator, ['invitations.create']);

    $this->withToken($plain)->postJson('/api/v1/invitations')->assertCreated()
        ->assertJsonPath('expires_at', now()->addDays(7)->toIso8601String());

    foreach ([['days' => 0], ['days' => 91], ['days' => 'x'], ['note' => str_repeat('n', 101)], ['note' => "a\u{202E}b"]] as $body) {
        $this->withToken($plain)->postJson('/api/v1/invitations', $body)->assertUnprocessable();
    }

    expect(Invitation::query()->count())->toBe(1);
});

it('refuses a token without invitations.create, without a token, and with a session cookie only', function () {
    $this->withToken(bearerWith($this->operator, ['catalog.browse']))->postJson('/api/v1/invitations')
        ->assertForbidden()->assertJsonPath('message', 'token_lacks_permission');
    auth()->forgetGuards();
    $this->flushHeaders();

    $this->postJson('/api/v1/invitations')->assertUnauthorized();
    // ADR-0007: the token path never reads the session.
    $this->actingAs($this->operator, 'web')->postJson('/api/v1/invitations')->assertUnauthorized();

    expect(Invitation::query()->count())->toBe(0);
});

it('refuses the token of an owner who lost invitations.create', function () {
    $plain = bearerWith($this->operator, ['invitations.create']);
    $this->operator->syncRoles([Roles::USER]);

    $this->withToken($plain)->postJson('/api/v1/invitations')
        ->assertForbidden()->assertJsonPath('message', 'owner_lacks_permission');
    expect(Invitation::query()->count())->toBe(0);
});

it('revokes an open invitation with a token and answers 404 for an unknown or used one', function () {
    $issued = app(InvitationService::class)->issue(Actor::user($this->operator));
    $plain = bearerWith($this->operator, ['invitations.revoke']);

    $this->withToken($plain)->deleteJson("/api/v1/invitations/{$issued->invitation->id}")->assertNoContent();
    expect($issued->invitation->fresh()?->revoked_at)->not->toBeNull();

    $this->withToken($plain)->deleteJson("/api/v1/invitations/{$issued->invitation->id}")->assertNotFound();
    $this->withToken($plain)->deleteJson('/api/v1/invitations/999999')->assertNotFound();
    $this->withToken($plain)->deleteJson('/api/v1/invitations/abc')->assertNotFound();

    $other = app(InvitationService::class)->issue(Actor::user($this->operator));
    auth()->forgetGuards();
    $this->withToken(bearerWith($this->operator, ['invitations.create']))->deleteJson("/api/v1/invitations/{$other->invitation->id}")
        ->assertForbidden()->assertJsonPath('message', 'token_lacks_permission');
    expect($other->invitation->fresh()?->revoked_at)->toBeNull();
});

it('issues an access invitation from the interface and lists it as open', function () {
    $response = appAs($this->operator)->postJson('/api/v1/app/invitations', ['days' => 1, 'note' => 'Noa'])
        ->assertCreated()->assertHeader('Cache-Control', 'no-store, private');

    expect($response->json('invitation'))->toMatchArray([
        'kind' => 'access', 'note' => 'Noa', 'created_by' => $this->operator->shownName(), 'via' => 'ui', 'for_user' => null,
    ])
        ->and($response->json('url'))->toStartWith(url('/invite').'#')
        ->and($response->json('invitation'))->not->toHaveKey('token_hash');
});

it('issues a recovery invitation from the interface only with users.manage and the account’s permissions', function () {
    $sam = User::factory()->withRole(Roles::USER)->create(['username' => 'sam']);

    appAs($this->operator)->postJson('/api/v1/app/invitations', ['user' => $sam->id])->assertCreated()
        ->assertJsonPath('invitation.kind', 'recovery')->assertJsonPath('invitation.for_user', 'sam');

    $inviters = Role::create(['name' => 'inviters', 'guard_name' => Roles::GUARD]);
    $inviters->givePermissionTo([Permission::InvitationsCreate->value]);
    $helper = User::factory()->withRole('inviters')->create();

    appAs($helper)->postJson('/api/v1/app/invitations', ['user' => $sam->id])->assertForbidden()
        ->assertJsonPath('message', 'privilege_escalation');
    appAs($helper)->postJson('/api/v1/app/invitations', ['user' => 999999])->assertUnprocessable();
    // A recovery invitation lasts three days at most; the answer names the field.
    appAs($this->operator)->postJson('/api/v1/app/invitations', ['user' => $sam->id, 'days' => 4])->assertUnprocessable()
        ->assertJsonValidationErrors(['days' => __('invitations.invalid.days')]);

    expect(Invitation::query()->where('kind', InvitationKind::Recovery)->count())->toBe(1);
});

it('refuses the interface path without its header and for a friend', function () {
    $this->actingAs($this->operator, 'web')->postJson('/api/v1/app/invitations')->assertForbidden();
    $friend = User::factory()->withRole(Roles::USER)->create();
    appAs($friend)->postJson('/api/v1/app/invitations')->assertForbidden();
    appAs($friend)->deleteJson('/api/v1/app/invitations/1')->assertForbidden();

    expect(Invitation::query()->count())->toBe(0);
});

it('never writes the token into the audit log', function () {
    $url = (string) appAs($this->operator)->postJson('/api/v1/app/invitations')->json('url');
    $token = substr($url, (int) strrpos($url, '#') + 1);

    expect(json_encode(AuditEntry::query()->get()->toArray()))->not->toContain($token)
        ->and(strlen($token))->toBe(43);
});
