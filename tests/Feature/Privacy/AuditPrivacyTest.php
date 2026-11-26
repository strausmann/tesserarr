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
use App\Authorization\Roles;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

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

it('keeps a role whose name looks like an address manageable and writes the name redacted', function () {
    $operator = User::factory()->operator()->create();
    $member = User::factory()->withRole(Roles::USER)->create();
    $name = 'ops@example.org';

    $this->actingAs($operator)->post('/roles', ['name' => $name, 'permissions' => ['audit.view']])->assertRedirect();
    $role = Role::findByName($name, Roles::GUARD);
    $this->actingAs($operator)->put('/roles/'.$role->id, ['permissions' => ['audit.view', 'catalog.browse']])->assertRedirect();
    $this->actingAs($operator)->put("/users/{$member->id}/role", ['role' => $name])->assertRedirect();
    $this->actingAs($operator)->put("/users/{$member->id}/role", ['role' => Roles::USER])->assertRedirect();
    $this->actingAs($operator)->delete('/roles/'.$role->id)->assertRedirect('/roles');

    $contexts = AuditEntry::query()->whereIn('action', [
        AuditAction::RoleCreated->value, AuditAction::RoleChanged->value, AuditAction::RoleAssigned->value, AuditAction::RoleDeleted->value,
    ])->pluck('context')->map(fn ($context) => json_encode($context))->implode("\n");

    expect($contexts)->not->toContain('example.org')
        ->and($contexts)->toContain('"role":"[email]"')
        ->and($contexts)->toContain('"previous":["[email]"]');
});
