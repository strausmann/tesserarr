<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\PiiInAuditContext;
use App\Audit\SecretInAuditContext;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The audit log names people by id (task 8a): an address in the context is
| refused, and names are looked up when the log is shown.
*/

it('refuses an email address in audit context', function (array $context, string $path) {
    expect(fn () => app(AuditLogger::class)->record(AuditAction::UserLocked, Actor::cli('ops'), null, $context))
        ->toThrow(function (PiiInAuditContext $e) use ($path) {
            expect($e->path)->toBe($path)->and($e->getMessage())->not->toContain('a@example.invalid');
        });

    expect(AuditEntry::query()->count())->toBe(0);
})->with([
    'flat' => [['note' => 'a@example.invalid'], 'note'],
    'inside text' => [['note' => 'sent to <a@example.invalid>'], 'note'],
    'nested' => [['to' => ['first' => 'a@example.invalid']], 'to.first'],
    'as a key' => [['a@example.invalid' => true], '[key]'],
]);

it('keeps the address check apart from the secret check', function () {
    // A secret key is refused for being a secret, not for an address.
    expect(fn () => app(AuditLogger::class)->record(AuditAction::UserLocked, Actor::cli('ops'), null, ['token' => 'x']))
        ->toThrow(SecretInAuditContext::class);
    app(AuditLogger::class)->record(AuditAction::UserLocked, Actor::cli('ops'), null, ['note' => 'user@host and @alice']);
    expect(AuditEntry::query()->count())->toBe(1);
});

it('resolves actor and subject ids to usernames when the audit log is shown', function () {
    $operator = User::factory()->operator()->create(['username' => 'olga', 'display_name' => '']);
    $alice = User::factory()->create(['username' => 'alice', 'display_name' => 'Alice A.']);
    app(AuditLogger::class)->record(AuditAction::UserLocked, Actor::user($operator), $alice);

    $entry = AuditEntry::query()->sole();
    expect($entry->actor_id)->toBe($operator->id)->and($entry->subject_id)->toBe($alice->id)
        ->and(json_encode($entry->getAttributes()))->not->toContain('olga')->not->toContain('Alice');

    // Renamed later: the log shows the name of now.
    $alice->forceFill(['display_name' => 'Alice B.'])->save();

    $this->actingAs($operator)->get('/audit')->assertInertia(fn (Assert $page) => $page
        ->where('entries.0.who', 'olga')
        ->where('entries.0.subject', 'Alice B.'));
    $this->actingAs($operator)->get("/users/{$alice->id}")->assertInertia(fn (Assert $page) => $page
        ->where('entries.0.who', 'olga')->where('entries.0.subject', 'Alice B.'));
});
