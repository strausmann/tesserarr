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
use Spatie\Permission\Models\Role;

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

it('rejects TRUNCATE of the audit log at database level', function () {
    app(AuditLogger::class)->record(AuditAction::RoleChanged, Actor::cli('ops'));

    expect(fn () => DB::transaction(fn () => DB::statement('truncate audit_entries')))
        ->toThrow(QueryException::class, 'audit_entries is append-only');
    expect(AuditEntry::query()->count())->toBe(1);
});

it('rolls a change back when its audit entry cannot be written', function (string $action, Closure $change) {
    // The audit insert of exactly this action fails; the change must go with it.
    DB::statement("alter table audit_entries add constraint test_refuse_action check (action <> '{$action}' or outcome <> 'done')");

    $unchanged = $change($this);

    expect($unchanged())->toBeTrue();
})->with([
    'role of an account (web)' => ['role.assign', function ($test) {
        $target = User::factory()->withRole('user')->create();
        $test->actingAs(User::factory()->operator()->create())->putJson("/users/{$target->id}/role", ['role' => 'operator'])->assertStatus(500);

        return fn () => $target->fresh()->getRoleNames()->all() === ['user'];
    }],
    'lock (web)' => ['user.lock', function ($test) {
        $target = User::factory()->withRole('user')->create();
        $test->actingAs(User::factory()->operator()->create())->postJson("/users/{$target->id}/lock")->assertStatus(500);

        return fn () => $target->fresh()->locked_at === null;
    }],
    'role permissions (web)' => ['role.update', function ($test) {
        $role = Role::create(['name' => 'helpers', 'guard_name' => 'web']);
        $test->actingAs(User::factory()->operator()->create())->putJson("/roles/{$role->id}", ['permissions' => ['audit.view']])->assertStatus(500);

        return fn () => $role->fresh()->permissions->isEmpty();
    }],
    'role created (web)' => ['role.create', function ($test) {
        $test->actingAs(User::factory()->operator()->create())->postJson('/roles', ['name' => 'helpers', 'permissions' => []])->assertStatus(500);

        return fn () => ! Role::query()->where('name', 'helpers')->exists();
    }],
    'role deleted (web)' => ['role.delete', function ($test) {
        Role::create(['name' => 'helpers', 'guard_name' => 'web']);
        $test->actingAs(User::factory()->operator()->create())->deleteJson('/roles/'.Role::findByName('helpers', 'web')->id)->assertStatus(500);

        return fn () => Role::query()->where('name', 'helpers')->exists();
    }],
    'role of an account (CLI)' => ['role.assign', function ($test) {
        $target = User::factory()->withRole('user')->create(['username' => 'bob']);
        expect(fn () => $test->artisan('tesserarr:role:assign', ['username' => 'bob', 'role' => 'operator'])->run())->toThrow(QueryException::class);

        return fn () => $target->fresh()->getRoleNames()->all() === ['user'];
    }],
    'lock (CLI)' => ['user.lock', function ($test) {
        User::factory()->operator()->create();
        $target = User::factory()->withRole('user')->create(['username' => 'bob']);
        expect(fn () => $test->artisan('tesserarr:user:lock', ['username' => 'bob'])->run())->toThrow(QueryException::class);

        return fn () => $target->fresh()->locked_at === null;
    }],
]);
