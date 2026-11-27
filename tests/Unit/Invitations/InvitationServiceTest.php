<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditOutcome;
use App\Authorization\Permission;
use App\Authorization\PrivilegeEscalation;
use App\Authorization\Roles;
use App\Identity\Identity;
use App\Identity\IdentityClaim;
use App\Identity\LoginMethod;
use App\Invitations\Invitation;
use App\Invitations\InvitationKind;
use App\Invitations\InvitationNotRedeemable;
use App\Invitations\InvitationNotRevocable;
use App\Invitations\InvitationService;
use App\Invitations\InvitationStore;
use App\Models\User;
use App\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

use function Tests\Support\overrideWith;

/*
| InvitationService (Spec 9.1, 9.3): an invitation is stored as a hash, is
| valid for 7 days by default, is used once, can be revoked, and its
| redemption is logged with identity, method, time and source address. A
| recovery invitation binds a new identity to an existing account.
*/

function invitations(): InvitationService
{
    return app(InvitationService::class);
}

/** Entries written by this test: the concurrency tests leave committed ones behind (the log refuses TRUNCATE). */
function newEntries(): Builder
{
    return AuditEntry::query()->where('id', '>', test()->auditBase);
}

function claim(string $subject = 'sub-1', LoginMethod $method = LoginMethod::Oidc, ?string $email = null, ?string $username = 'sam'): IdentityClaim
{
    return new IdentityClaim($method, 'example', $subject, $username, $email, 'Sam');
}

beforeEach(function () {
    $this->auditBase = (int) (AuditEntry::query()->max('id') ?? 0);
    $this->operator = User::factory()->operator()->create(['username' => 'alex']);
});

it('stores only the sha-256 hash', function () {
    $issued = invitations()->issue(Actor::user($this->operator));

    $row = (array) DB::table('invitations')->where('id', $issued->invitation->id)->first();

    expect($issued->plainToken)->toMatch('/\A[A-Za-z0-9_-]{43}\z/')
        ->and($row['token_hash'])->toBe(hash('sha256', $issued->plainToken))
        ->and(json_encode($row))->not->toContain($issued->plainToken)
        ->and($issued->url)->toBe(url('/invite').'#'.$issued->plainToken)
        ->and(json_encode(newEntries()->get()->toArray()))->not->toContain($issued->plainToken);
});

it('expires after 7 days by default', function () {
    $this->freezeSecond();
    $issued = invitations()->issue(Actor::user($this->operator));

    expect($issued->invitation->fresh()?->expires_at->equalTo(CarbonImmutable::now()->addDays(7)))->toBeTrue();

    $short = invitations()->issue(Actor::console(), ttl: CarbonInterval::day());
    expect($short->invitation->fresh()?->expires_at->equalTo(CarbonImmutable::now()->addDay()))->toBeTrue();
});

it('takes its default validity from the general setting, and the environment override wins', function () {
    $this->freezeSecond();
    app(SettingsRegistry::class)->update(['invitations_default_days' => 3], Actor::cli('test'));
    expect(invitations()->issue(Actor::console())->invitation->fresh()?->expires_at->equalTo(CarbonImmutable::now()->addDays(3)))->toBeTrue();

    overrideWith('TESSERARR_INVITATION_DAYS', '12');
    expect(invitations()->issue(Actor::console())->invitation->fresh()?->expires_at->equalTo(CarbonImmutable::now()->addDays(12)))->toBeTrue();
});

it('cannot be redeemed twice or after revocation', function () {
    $once = invitations()->issue(Actor::user($this->operator));
    invitations()->redeem($once->plainToken, claim('a'), '192.0.2.10');

    expect(fn () => invitations()->redeem($once->plainToken, claim('b'), '192.0.2.11'))
        ->toThrow(InvitationNotRedeemable::class);

    $revoked = invitations()->issue(Actor::user($this->operator));
    invitations()->revoke($revoked->invitation->id, Actor::user($this->operator));

    expect(fn () => invitations()->redeem($revoked->plainToken, claim('c'), '192.0.2.12'))
        ->toThrow(InvitationNotRedeemable::class)
        ->and(User::query()->count())->toBe(2)
        ->and(fn () => invitations()->revoke($revoked->invitation->id, Actor::user($this->operator)))
        ->toThrow(InvitationNotRevocable::class)
        ->and(fn () => invitations()->revoke($once->invitation->id, Actor::user($this->operator)))
        ->toThrow(InvitationNotRevocable::class);
});

it('refuses an expired or unknown invitation with one message and logs the reason', function () {
    $issued = invitations()->issue(Actor::user($this->operator));
    DB::table('invitations')->where('id', $issued->invitation->id)->update(['expires_at' => now()->subMinute()]);

    $messages = [];
    foreach ([$issued->plainToken, str_repeat('x', 43), 'short'] as $token) {
        try {
            invitations()->redeem($token, claim(), '192.0.2.20');
        } catch (InvitationNotRedeemable $e) {
            $messages[] = $e->getMessage();
        }
    }

    $reasons = newEntries()->where('action', AuditAction::InvitationRedeemed->value)
        ->where('outcome', AuditOutcome::Denied)->orderBy('id')->get()->map(fn (AuditEntry $e) => $e->context['reason'])->all();

    expect(array_unique($messages))->toBe(['invitation_not_redeemable'])
        ->and($reasons)->toBe(['expired', 'unknown', 'unknown'])
        ->and(User::query()->count())->toBe(1);
});

it('lets a foreign running reservation block a redemption and an expired one not', function () {
    $issued = invitations()->issue(Actor::user($this->operator));
    $store = app(InvitationStore::class);
    $hash = Invitation::hashOf($issued->plainToken);

    expect($store->reserve($hash, str_repeat('a', 64), null, CarbonImmutable::now()->addMinutes(15)))->toBe($issued->invitation->id)
        ->and($store->consume($hash, null))->toBeNull()
        ->and($store->consume($hash, str_repeat('b', 64)))->toBeNull()
        ->and($store->reserve($hash, str_repeat('c', 64), null, CarbonImmutable::now()->addMinutes(15)))->toBeNull();

    DB::table('invitations')->where('id', $issued->invitation->id)->update(['reserved_until' => now()->subSecond()]);

    expect($store->consume($hash, null))->toBe($issued->invitation->id);
});

it('lets the own reservation complete only while it runs', function () {
    $store = app(InvitationStore::class);
    $hash = Invitation::hashOf(invitations()->issue(Actor::user($this->operator))->plainToken);
    $mine = str_repeat('d', 64);

    $store->reserve($hash, $mine, null, CarbonImmutable::now()->addMinutes(15));
    DB::table('invitations')->where('token_hash', $hash)->update(['reserved_until' => now()->subSecond()]);
    expect($store->consume($hash, $mine))->not->toBeNull();

    $other = Invitation::hashOf(invitations()->issue(Actor::user($this->operator))->plainToken);
    $store->reserve($other, $mine, null, CarbonImmutable::now()->addMinutes(15));
    expect($store->consume($other, $mine))->not->toBeNull();
});

it('reserves an invitation at most five times', function () {
    $store = app(InvitationStore::class);
    $hash = Invitation::hashOf(invitations()->issue(Actor::user($this->operator))->plainToken);

    $won = 0;
    for ($i = 0; $i < 7; $i++) {
        if ($store->reserve($hash, str_repeat((string) $i, 64), null, CarbonImmutable::now()->addMinutes(15)) !== null) {
            $won++;
        }
        DB::table('invitations')->where('token_hash', $hash)->update(['reserved_until' => now()->subSecond()]);
    }

    expect($won)->toBe(InvitationStore::MAX_RESERVATIONS);
});

it('makes a new account with the user role and the identity for an access invitation', function () {
    $issued = invitations()->issue(Actor::user($this->operator));

    $redeemed = invitations()->redeem($issued->plainToken, claim('s-9', email: 'sam@idp.example.org'), '192.0.2.30');

    $identity = Identity::query()->sole();
    expect($redeemed->kind)->toBe(InvitationKind::Access)
        ->and($redeemed->consumed)->toBeTrue()
        ->and($redeemed->user->username)->toBe('sam')
        ->and($redeemed->user->display_name)->toBe('Sam')
        ->and($redeemed->user->getRoleNames()->all())->toBe([Roles::USER])
        ->and($identity->user_id)->toBe($redeemed->user->id)
        ->and($identity->email)->toBe('sam@idp.example.org')
        // The provider's address is the identity's, never the account address.
        ->and($redeemed->user->fresh()?->email)->toBeNull()
        ->and($issued->invitation->fresh()?->redeemed_by_user_id)->toBe($redeemed->user->id);
});

it('binds a recovery invitation to the existing user and keeps grants and audit history', function () {
    $helpers = Role::create(['name' => 'helpers', 'guard_name' => Roles::GUARD]);
    $helpers->givePermissionTo(Permission::StatusView->value);
    $sam = User::factory()->withRole('helpers')->create(['username' => 'sam']);
    Identity::query()->create(['user_id' => $sam->id, 'method' => 'oidc', 'provider_key' => 'example', 'subject' => 'old']);
    $history = AuditEntry::query()->count();

    $issued = invitations()->issue(Actor::user($this->operator), InvitationKind::Recovery, $sam);
    $redeemed = invitations()->redeem($issued->plainToken, claim('new-device', LoginMethod::Plex), '192.0.2.40');

    expect($redeemed->user->id)->toBe($sam->id)
        ->and($redeemed->kind)->toBe(InvitationKind::Recovery)
        ->and(User::query()->count())->toBe(2)
        ->and($sam->fresh()?->getRoleNames()->all())->toBe(['helpers'])
        ->and($sam->identities()->pluck('subject')->sort()->values()->all())->toBe(['new-device', 'old'])
        ->and(AuditEntry::query()->count())->toBe($history + 2);
});

it('logs identity, method, time and source address of every redemption', function () {
    $issued = invitations()->issue(Actor::user($this->operator));
    $this->freezeSecond();

    $redeemed = invitations()->redeem($issued->plainToken, claim('s-1', LoginMethod::Header), '198.51.100.7');

    $entry = newEntries()->where('action', AuditAction::InvitationRedeemed->value)->sole();
    expect($entry->outcome)->toBe(AuditOutcome::Done)
        ->and($entry->actor_type)->toBe(Actor::USER)
        ->and($entry->actor_id)->toBe($redeemed->user->id)
        ->and($entry->subject_type)->toBe('user')
        ->and($entry->context)->toMatchArray([
            'invitation' => $issued->invitation->id,
            'identity' => Identity::query()->sole()->id,
            'method' => 'header',
            'provider' => 'example',
            'ip' => '198.51.100.7',
        ])
        ->and($entry->occurred_at->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and($issued->invitation->fresh()?->redeemed_ip)->toBe('198.51.100.7');
});

it('signs the existing account in and leaves the invitation unused when the identity is already known', function () {
    $first = invitations()->issue(Actor::user($this->operator));
    $owner = invitations()->redeem($first->plainToken, claim('same'), '192.0.2.50')->user;
    $second = invitations()->issue(Actor::user($this->operator));

    $again = invitations()->redeem($second->plainToken, claim('same'), '192.0.2.51');

    expect($again->user->id)->toBe($owner->id)
        ->and($again->consumed)->toBeFalse()
        ->and($second->invitation->fresh()?->redeemed_at)->toBeNull()
        ->and(User::query()->count())->toBe(2);
});

it('refuses a recovery invitation for an identity of another account', function () {
    $other = User::factory()->create();
    Identity::query()->create(['user_id' => $other->id, 'method' => 'oidc', 'provider_key' => 'example', 'subject' => 'theirs']);
    $sam = User::factory()->create();
    $issued = invitations()->issue(Actor::console(), InvitationKind::Recovery, $sam);

    expect(fn () => invitations()->redeem($issued->plainToken, claim('theirs'), '192.0.2.60'))
        ->toThrow(InvitationNotRedeemable::class)
        ->and($issued->invitation->fresh()?->redeemed_at)->toBeNull()
        ->and($sam->identities()->count())->toBe(0);
});

it('gives two new accounts that prefer the same username different ones', function () {
    $a = invitations()->issue(Actor::user($this->operator));
    $b = invitations()->issue(Actor::user($this->operator));

    $one = invitations()->redeem($a->plainToken, claim('x1', username: 'Sam'), '192.0.2.70')->user;
    $two = invitations()->redeem($b->plainToken, claim('x2', username: 'sam'), '192.0.2.71')->user;

    expect([$one->username, $two->username])->toBe(['sam', 'sam2']);
});

it('refuses an invitation from an account without invitations.create and logs the refusal', function () {
    $friend = User::factory()->withRole(Roles::USER)->create();

    expect(fn () => invitations()->issue(Actor::user($friend)))->toThrow(PrivilegeEscalation::class)
        ->and(Invitation::query()->count())->toBe(0)
        ->and(newEntries()->where('action', 'invitation.issue')->where('outcome', AuditOutcome::Denied)->exists())->toBeTrue();
});

it('refuses a recovery invitation for an account with permissions the issuer lacks', function () {
    $inviters = Role::create(['name' => 'inviters', 'guard_name' => Roles::GUARD]);
    $inviters->givePermissionTo([Permission::InvitationsCreate->value, Permission::UsersManage->value, Permission::CatalogBrowse->value, Permission::TokensManageOwn->value]);
    $helper = User::factory()->withRole('inviters')->create();

    // An operator's account would be the helper's way to every permission.
    expect(fn () => invitations()->issue(Actor::user($helper), InvitationKind::Recovery, $this->operator))
        ->toThrow(PrivilegeEscalation::class);

    $plain = User::factory()->withRole(Roles::USER)->create();
    expect(invitations()->issue(Actor::user($helper), InvitationKind::Recovery, $plain)->invitation->for_user_id)->toBe($plain->id);

    // invitations.create alone is no recovery.
    $inviters->revokePermissionTo(Permission::UsersManage->value);
    expect(fn () => invitations()->issue(Actor::user($helper->fresh()), InvitationKind::Recovery, $plain))
        ->toThrow(PrivilegeEscalation::class);
});

it('refuses to revoke without invitations.revoke', function () {
    $issued = invitations()->issue(Actor::user($this->operator));
    $creators = Role::create(['name' => 'creators', 'guard_name' => Roles::GUARD]);
    $creators->givePermissionTo(Permission::InvitationsCreate->value);
    $creator = User::factory()->withRole('creators')->create();

    expect(fn () => invitations()->revoke($issued->invitation->id, Actor::user($creator)))->toThrow(PrivilegeEscalation::class)
        ->and($issued->invitation->fresh()?->revoked_at)->toBeNull();
});

it('refuses a recovery invitation without an account and an access invitation with one', function () {
    expect(fn () => invitations()->issue(Actor::console(), InvitationKind::Recovery))->toThrow(InvalidArgumentException::class)
        ->and(fn () => invitations()->issue(Actor::console(), InvitationKind::Access, $this->operator))->toThrow(InvalidArgumentException::class);
});

it('stores the note encrypted and refuses control characters in it', function () {
    $issued = invitations()->issue(Actor::console(), note: '  For Sam  ');

    expect($issued->invitation->fresh()?->note)->toBe('For Sam')
        ->and((string) DB::table('invitations')->value('note'))->not->toContain('Sam')
        ->and(fn () => invitations()->issue(Actor::console(), note: "a\u{0007}b"))->toThrow(InvalidArgumentException::class)
        ->and(fn () => invitations()->issue(Actor::console(), note: str_repeat('n', 101)))->toThrow(InvalidArgumentException::class);
});

it('finds only open invitations and never looks up something that is no token', function () {
    $open = invitations()->issue(Actor::console());
    $gone = invitations()->issue(Actor::console());
    invitations()->revoke($gone->invitation->id, Actor::console());

    DB::enableQueryLog();
    $none = invitations()->find('not-a-token');
    $queries = count(DB::getQueryLog());

    expect(invitations()->find($open->plainToken)?->id)->toBe($open->invitation->id)
        ->and(invitations()->find($gone->plainToken))->toBeNull()
        ->and($none)->toBeNull()
        ->and($queries)->toBe(0);
});
