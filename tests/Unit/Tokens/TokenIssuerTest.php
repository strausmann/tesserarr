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
use App\Authorization\TokenPreset;
use App\Models\User;
use App\Tokens\InvalidTokenRequest;
use App\Tokens\TokenIssuer;
use App\Tokens\TokenRevoker;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken;

/*
| TokenIssuer (Spec 9.5): a token is a user's, carries a selection from the
| catalogue that is never more than its creator holds, is stored as a hash
| and is shown once. Every issue and every refusal is in the audit log, and
| no entry carries the token.
*/

function issuer(): TokenIssuer
{
    return app(TokenIssuer::class);
}

beforeEach(function () {
    $this->operator = User::factory()->operator()->create(['username' => 'alex']);
    $this->friend = User::factory()->withRole('user')->create(['username' => 'sam']);
});

it('issues a token with exactly the chosen permissions, in catalogue order', function () {
    $new = issuer()->issue($this->operator, Actor::user($this->operator), 'Katalog-Export', [
        Permission::StatusView, Permission::CatalogBrowse, Permission::StatusView,
    ]);

    expect($new->accessToken->abilities)->toBe(['catalog.browse', 'status.view'])
        ->and($new->accessToken->name)->toBe('Katalog-Export')
        ->and($new->accessToken->tokenable_id)->toBe($this->operator->id)
        ->and($new->accessToken->expires_at)->toBeNull();
});

it('refuses a token with a permission its creator lacks', function () {
    issuer()->issue($this->friend, Actor::user($this->friend), 't', [Permission::UsersManage]);
})->throws(PrivilegeEscalation::class);

it('refuses satellite.execute on a user token', function () {
    issuer()->issue($this->operator, Actor::user($this->operator), 't', [Permission::SatelliteExecute]);
})->throws(PrivilegeEscalation::class);

it('refuses satellite.execute from the console as well', function () {
    issuer()->issue($this->operator, Actor::console(), 't', [Permission::SatelliteExecute]);
})->throws(PrivilegeEscalation::class);

it('records a refused issue and creates no token', function () {
    try {
        issuer()->issue($this->friend, Actor::user($this->friend), 'admin please', [Permission::CatalogBrowse, Permission::UsersManage]);
    } catch (PrivilegeEscalation) {
    }

    expect(PersonalAccessToken::query()->count())->toBe(0);
    $entry = AuditEntry::query()->where('action', AuditAction::TokenIssued->value)->sole();
    expect($entry->outcome)->toBe(AuditOutcome::Denied)
        ->and($entry->actor_id)->toBe($this->friend->id)
        ->and($entry->context['abilities'])->toBe(['users.manage', 'catalog.browse']);
});

it('lets a user issue a token only for themselves in the interface and the API', function (string $kind) {
    $by = $kind === 'user'
        ? Actor::user($this->operator)
        : Actor::token($this->operator->createToken('x', ['tokens.manage_own'])->accessToken, $this->operator);

    expect(fn () => issuer()->issue($this->friend, $by, 'for sam', [Permission::CatalogBrowse]))
        ->toThrow(InvalidTokenRequest::class, InvalidTokenRequest::OWNER);
    expect(PersonalAccessToken::query()->where('tokenable_id', $this->friend->id)->count())->toBe(0);
})->with(['user', 'token']);

it('issues from the console for a named account, capped at what the account holds', function () {
    $new = issuer()->issue($this->friend, Actor::console(), 'script', [Permission::CatalogBrowse]);
    expect($new->accessToken->tokenable_id)->toBe($this->friend->id);

    expect(fn () => issuer()->issue($this->friend, Actor::console(), 'script', [Permission::UsersManage]))
        ->toThrow(PrivilegeEscalation::class);
});

it('refuses a satellite as the issuer', function () {
    issuer()->issue($this->operator, Actor::satellite(1, 'cellar'), 't', [Permission::CatalogBrowse]);
})->throws(InvalidTokenRequest::class, InvalidTokenRequest::OWNER);

it('refuses a token of a locked account', function () {
    $this->friend->forceFill(['locked_at' => now()])->save();

    issuer()->issue($this->friend, Actor::console(), 't', [Permission::CatalogBrowse]);
})->throws(PrivilegeEscalation::class);

it('refuses a token without a permission (never a wildcard)', function () {
    issuer()->issue($this->operator, Actor::user($this->operator), 't', []);
})->throws(InvalidTokenRequest::class, InvalidTokenRequest::PERMISSIONS);

it('refuses an empty or too long name', function (string $name) {
    issuer()->issue($this->operator, Actor::user($this->operator), $name, [Permission::CatalogBrowse]);
})->with([
    'empty' => '',
    'blank' => "  \t ",
    'too long' => str_repeat('x', TokenIssuer::NAME_MAX + 1),
    'control character' => "line\nbreak",
])->throws(InvalidTokenRequest::class, InvalidTokenRequest::NAME);

it('refuses an expiry that has passed', function () {
    issuer()->issue($this->operator, Actor::user($this->operator), 't', [Permission::CatalogBrowse], CarbonImmutable::now()->subMinute());
})->throws(InvalidTokenRequest::class, InvalidTokenRequest::EXPIRY);

it('stores tokens hashed and shows the plain text only in the returned object', function () {
    $new = issuer()->issue($this->operator, Actor::user($this->operator), 't', [Permission::CatalogBrowse], CarbonImmutable::now()->addDays(30));

    [$id, $secret] = explode('|', $new->plainTextToken, 2);
    $row = PersonalAccessToken::query()->findOrFail((int) $id);

    expect($secret)->toStartWith(TokenIssuer::PREFIX)
        ->and($row->getAttributes()['token'])->toBe(hash('sha256', $secret))
        ->and($row->getAttributes()['token'])->not->toContain($secret)
        ->and($row->expires_at?->toDateString())->toBe(CarbonImmutable::now()->addDays(30)->toDateString());
});

it('writes an audit entry for the issue without the token in it', function () {
    $new = issuer()->issue($this->operator, Actor::user($this->operator), 'export', [Permission::CatalogBrowse]);

    $entry = AuditEntry::query()->where('action', AuditAction::TokenIssued->value)->sole();
    [, $secret] = explode('|', $new->plainTextToken, 2);

    expect($entry->outcome)->toBe(AuditOutcome::Done)
        ->and($entry->subject_type)->toBe('token')
        ->and($entry->subject_id)->toBe($new->accessToken->id)
        ->and($entry->context)->toMatchArray(['label' => 'export', 'abilities' => ['catalog.browse'], 'owner' => $this->operator->id])
        ->and(json_encode($entry->getAttributes()))->not->toContain($secret)
        ->and(json_encode($entry->getAttributes()))->not->toContain(hash('sha256', $secret));
});

it('writes a name that looks like an address as [email] in the log', function () {
    issuer()->issue($this->operator, Actor::user($this->operator), 'bot for ops@example.org', [Permission::CatalogBrowse]);

    expect(AuditEntry::query()->where('action', AuditAction::TokenIssued->value)->sole()->context['label'])
        ->toBe('bot for [email]');
});

it('offers presets without an administrative permission', function () {
    expect(TokenPreset::ArrIntegration->permissions())->toBe([Permission::CatalogBrowse])
        ->and(TokenPreset::InvitationIssuer->permissions())->toBe([Permission::InvitationsCreate]);

    foreach (TokenPreset::cases() as $preset) {
        expect(array_intersect(
            Permission::values($preset->permissions()),
            Permission::values([Permission::UsersManage, Permission::RolesManage, Permission::SettingsManage, Permission::TokensManageAll, Permission::SatelliteExecute]),
        ))->toBe([]);
    }
});

it('revokes a token and records it', function () {
    $new = issuer()->issue($this->operator, Actor::user($this->operator), 'old', [Permission::CatalogBrowse]);

    app(TokenRevoker::class)->revoke($new->accessToken, Actor::console());

    expect(PersonalAccessToken::query()->find($new->accessToken->id))->toBeNull();
    $entry = AuditEntry::query()->where('action', AuditAction::TokenRevoked->value)->sole();
    expect($entry->actor_type)->toBe(Actor::CLI)
        ->and($entry->subject_type)->toBe('token')
        ->and($entry->subject_id)->toBe($new->accessToken->id)
        ->and($entry->context)->toMatchArray(['label' => 'old', 'owner' => $this->operator->id]);
});

it('keeps the token when the audit entry cannot be written', function () {
    // The entry is part of the change: a refused write rolls the revocation back.
    $new = issuer()->issue($this->operator, Actor::user($this->operator), 'keep', [Permission::CatalogBrowse]);
    AuditEntry::creating(fn () => throw new RuntimeException('audit store down'));

    expect(fn () => app(TokenRevoker::class)->revoke($new->accessToken, Actor::console()))
        ->toThrow(RuntimeException::class, 'audit store down')
        ->and(PersonalAccessToken::query()->find($new->accessToken->id))->not->toBeNull();
});

it('creates no token when the audit entry cannot be written', function () {
    AuditEntry::creating(fn () => throw new RuntimeException('audit store down'));

    expect(fn () => issuer()->issue($this->operator, Actor::user($this->operator), 't', [Permission::CatalogBrowse]))
        ->toThrow(RuntimeException::class, 'audit store down')
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});
