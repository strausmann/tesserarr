<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Authorization\Roles;
use App\Models\User;

it('assigns a role by username and names the affected account', function () {
    $bob = User::factory()->withRole(Roles::USER)->create(['username' => 'bob']);

    $this->artisan('tesserarr:role:assign', ['username' => 'bob', 'role' => 'operator'])
        ->expectsOutputToContain("bob (id {$bob->id})")
        ->assertSuccessful();

    $entry = AuditEntry::query()->where('action', 'role.assign')->sole();
    expect($bob->fresh()?->getRoleNames()->all())->toBe([Roles::OPERATOR])
        ->and($entry->actor_type)->toBe('cli')
        ->and($entry->subject_id)->toBe($bob->id);
});

it('takes effect on the very next request of that user', function () {
    $bob = User::factory()->withRole(Roles::USER)->create(['username' => 'bob']);
    $this->actingAs($bob)->get('/audit')->assertForbidden();

    $this->artisan('tesserarr:role:assign', ['username' => 'bob', 'role' => 'operator'])->assertSuccessful();

    $this->get('/audit')->assertOk();
});

it('refuses an unknown role and an unknown username', function () {
    User::factory()->withRole(Roles::USER)->create(['username' => 'bob']);

    $this->artisan('tesserarr:role:assign', ['username' => 'bob', 'role' => 'nobody'])
        ->expectsOutputToContain('No role "nobody"')->assertFailed();
    $this->artisan('tesserarr:role:assign', ['username' => 'carol', 'role' => 'operator'])
        ->expectsOutputToContain('No account with the username "carol"')->assertFailed();
});

it('refuses to demote the last operator from the command line', function () {
    User::factory()->operator()->create(['username' => 'alice']);

    $this->artisan('tesserarr:role:assign', ['username' => 'alice', 'role' => 'user'])
        ->expectsOutputToContain('last active operator')->assertFailed();

    expect(AuditEntry::query()->where('action', 'role.assign')->where('outcome', 'denied')->count())->toBe(1);
});

it('locks and unlocks an account and logs it with the console as origin', function () {
    User::factory()->operator()->create(['username' => 'alice']);
    $bob = User::factory()->withRole(Roles::USER)->create(['username' => 'bob']);

    $this->artisan('tesserarr:user:lock', ['username' => 'bob'])->expectsOutputToContain("bob (id {$bob->id})")->assertSuccessful();
    expect($bob->fresh()?->locked_at)->not->toBeNull();

    $this->artisan('tesserarr:user:unlock', ['username' => 'bob'])->expectsOutputToContain("bob (id {$bob->id})")->assertSuccessful();
    expect($bob->fresh()?->locked_at)->toBeNull()
        ->and(AuditEntry::query()->where('actor_type', 'cli')->whereIn('action', ['user.lock', 'user.unlock'])->count())->toBe(2);
});

it('refuses to lock the last active operator from the command line', function () {
    $alice = User::factory()->operator()->create(['username' => 'alice']);

    $this->artisan('tesserarr:user:lock', ['username' => 'alice'])
        ->expectsOutputToContain('last active operator')->assertFailed();

    expect($alice->fresh()?->locked_at)->toBeNull();
});

it('escapes control characters in CLI listings', function () {
    $bob = User::factory()->withRole(Roles::USER)->create(['username' => 'bob', 'display_name' => "Bob\e[2J<error>x</error>\x07"]);

    $this->artisan('tesserarr:role:assign', ['username' => 'bob', 'role' => 'user'])
        ->expectsOutputToContain('Bob\x1b[2J<error>x</error>\x07')
        ->doesntExpectOutputToContain("\e[2J")
        ->assertSuccessful();

    expect($bob->id)->toBeInt();
});
