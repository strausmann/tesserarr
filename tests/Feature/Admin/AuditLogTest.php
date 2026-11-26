<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Authorization\Roles;
use App\Models\User;
use App\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function logEntries(int $count, Actor $actor, ?User $subject = null): void
{
    foreach (range(1, $count) as $i) {
        app(AuditLogger::class)->record(AuditAction::UserLocked, $actor, $subject, ['n' => $i]);
    }
}

it('lists the newest entries first, a page as long as ui.page_size, with links to older and newer ones', function () {
    $operator = User::factory()->operator()->create(['username' => 'alice', 'display_name' => 'Alice']);
    logEntries(53, Actor::user($operator));
    app(SettingsRegistry::class)->update(['ui_page_size' => 25], Actor::cli('ops'));

    $first = $this->actingAs($operator)->get('/audit?source=ui')->assertOk();
    $first->assertInertia(fn (Assert $page) => $page
        ->component('admin/Audit')
        ->has('entries', 25)
        ->where('entries.0.context.n', 53)
        ->where('entries.0.who', 'Alice')
        ->where('entries.0.source', 'ui')
        ->where('total', 53)
        ->where('newer', null)
        ->where('older', fn ($url) => is_string($url) && str_contains($url, 'before=')));

    $older = $first->viewData('page')['props']['older'];
    $this->get($older)->assertInertia(fn (Assert $page) => $page
        ->has('entries', 25)
        ->where('entries.0.context.n', 28)
        ->where('newer', fn ($url) => is_string($url) && str_contains($url, 'after=')));
});

it('filters by period, origin, actor and action', function () {
    $operator = User::factory()->operator()->create(['username' => 'alice']);
    logEntries(2, Actor::user($operator));
    logEntries(3, Actor::cli('ops'));
    app(AuditLogger::class)->record(AuditAction::RoleCreated, Actor::cli('ops'));
    DB::table('audit_entries')->insert([
        'action' => 'user.lock', 'actor_type' => 'cli', 'actor_label' => 'ops', 'context' => '{}', 'outcome' => 'done',
        'occurred_at' => Date::now()->subDays(10),
    ]);

    $this->actingAs($operator);
    $this->get('/audit?source=cli')->assertInertia(fn (Assert $page) => $page->where('total', 4));
    $this->get('/audit?source=cli&period=30d')->assertInertia(fn (Assert $page) => $page->where('total', 5));
    $this->get("/audit?who=user:{$operator->id}")->assertInertia(fn (Assert $page) => $page->where('total', 2));
    $this->get('/audit?q=role.create')->assertInertia(fn (Assert $page) => $page->where('total', 1));
    $this->get('/audit?period=1y')->assertSessionHasErrors('period');
});

it('names a person by their current username, never by an address', function () {
    $operator = User::factory()->operator()->create(['username' => 'alice', 'display_name' => '', 'email' => 'alice@example.org']);
    logEntries(1, Actor::user($operator));

    $response = $this->actingAs($operator)->get('/audit');

    $response->assertInertia(fn (Assert $page) => $page->where('entries.0.who', 'alice'));
    expect(json_encode($response->viewData('page')['props']['entries']))->not->toContain('alice@example.org');
});

it('refuses the log to a user without audit.view', function () {
    $friend = User::factory()->withRole(Roles::USER)->create();

    $this->actingAs($friend)->getJson('/audit')->assertForbidden()->assertJsonPath('message', 'owner_lacks_permission');
});

it('names the deleted role on the log page, though the role is gone', function () {
    $operator = User::factory()->operator()->create();
    $role = Role::create(['name' => 'helpers', 'guard_name' => Roles::GUARD]);

    $this->actingAs($operator)->delete("/roles/{$role->id}")->assertRedirect();

    $entry = AuditEntry::query()->where('action', 'role.delete')->sole();
    expect($entry->subject_type)->toBe('role')
        ->and($entry->subject_id)->toBe($role->id)
        ->and($entry->context['role'])->toBe('helpers');

    $this->get('/audit')->assertInertia(fn (Assert $page) => $page
        ->where('entries.0.action', 'role.delete')
        ->where('entries.0.subject', 'helpers'));
});
