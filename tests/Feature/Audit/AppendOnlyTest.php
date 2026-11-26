<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Audit\SecretInAuditContext;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('rejects UPDATE on an existing audit row at database level', function () {
    app(AuditLogger::class)->record(AuditAction::RoleChanged, Actor::cli('ops'));

    expect(fn () => DB::table('audit_entries')->update(['action' => 'x']))
        ->toThrow(QueryException::class, 'audit_entries is append-only');
});

it('rejects DELETE on an existing audit row at database level', function () {
    app(AuditLogger::class)->record(AuditAction::RoleChanged, Actor::cli('ops'));

    expect(fn () => DB::table('audit_entries')->delete())
        ->toThrow(QueryException::class, 'audit_entries is append-only');
});

it('refuses audit context with a secret-like key', function (string $key) {
    app(AuditLogger::class)->record(AuditAction::RoleChanged, Actor::cli('ops'), null, [$key => 'x']);
})->with(['api_token', 'password', 'client_secret', 'Authorization', 'api_key', 'privateKey', 'recovery_codes', 'cookie'])
    ->throws(SecretInAuditContext::class);

it('refuses a secret-like key nested in the context', function () {
    app(AuditLogger::class)->record(AuditAction::RoleChanged, Actor::cli('ops'), null, ['changes' => ['new' => ['token' => 'x']]]);
})->throws(SecretInAuditContext::class, 'changes.new.token');

it('writes nothing when the context is refused', function () {
    try {
        app(AuditLogger::class)->record(AuditAction::RoleChanged, Actor::cli('ops'), null, ['secret' => 'x']);
    } catch (SecretInAuditContext) {
    }

    expect(AuditEntry::query()->count())->toBe(0);
});

it('records who did what to which subject, by id and not by address', function () {
    $actor = User::factory()->create(['username' => 'alice', 'email' => 'alice@example.org']);
    $subject = User::factory()->create(['username' => 'bob']);

    $entry = app(AuditLogger::class)->record(AuditAction::UserLocked, Actor::user($actor), $subject, ['reason' => 'test']);

    $row = (array) DB::table('audit_entries')->where('id', $entry->id)->first();
    expect($row['action'])->toBe('user.lock')
        ->and($row['actor_type'])->toBe('user')
        ->and((int) $row['actor_id'])->toBe($actor->id)
        ->and($row['subject_type'])->toBe('user')
        ->and((int) $row['subject_id'])->toBe($subject->id)
        ->and($row['outcome'])->toBe('done')
        ->and(json_decode((string) $row['context'], true))->toBe(['reason' => 'test'])
        ->and(implode(' ', array_map('strval', $row)))->not->toContain('alice@example.org');
});

it('records the console user as the origin of a CLI action', function () {
    $entry = app(AuditLogger::class)->record(AuditAction::RoleAssigned, Actor::cli('ops'), null, [], AuditOutcome::Denied);

    expect($entry->actor_type)->toBe('cli')
        ->and($entry->actor_label)->toBe('ops')
        ->and($entry->actor_id)->toBeNull()
        ->and($entry->outcome)->toBe(AuditOutcome::Denied);
});

it('names the origin of each kind of actor', function () {
    $owner = User::factory()->create();
    $token = $owner->createToken('test', ['audit.view'])->accessToken;

    expect(Actor::user($owner)->source())->toBe('ui')
        ->and(Actor::cli('ops')->source())->toBe('cli')
        ->and(Actor::token($token, $owner)->source())->toBe('api')
        ->and(Actor::token($token, $owner)->id)->toBe($owner->id)
        ->and(Actor::token($token, $owner)->label)->toBe((string) $token->getKey())
        ->and(Actor::satellite(4, 'cellar')->source())->toBe('api')
        ->and(Actor::console()->type)->toBe('cli')
        ->and(Actor::console()->label)->not->toBe('');
});
