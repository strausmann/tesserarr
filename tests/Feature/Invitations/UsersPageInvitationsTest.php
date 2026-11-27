<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Authorization\Permission;
use App\Authorization\Roles;
use App\Invitations\InvitationService;
use App\Models\User;
use App\Settings\SettingsRegistry;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;

/*
| The open invitations on the user list (Users.dc.html): listed for whom may
| make or revoke them, newest first, without redeemed, revoked or expired ones,
| and never with their hash.
*/

it('lists the open invitations for an operator, newest first, with the validity the dialog preselects', function () {
    $operator = User::factory()->operator()->create(['display_name' => 'Alex']);
    $service = app(InvitationService::class);
    $first = $service->issue(Actor::user($operator), note: 'Mika');
    $second = $service->issue(Actor::console(), note: 'Noa');
    $gone = $service->issue(Actor::console());
    $service->revoke($gone->invitation->id, Actor::console());
    app(SettingsRegistry::class)->update(['invitations_default_days' => 14], Actor::cli('test'));

    $this->actingAs($operator)->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('admin/Users')
        ->where('invitation_days', 14)
        ->has('invitations', 2)
        ->where('invitations.0.id', $second->invitation->id)
        ->where('invitations.0.via', 'cli')
        ->where('invitations.0.created_by', null)
        ->where('invitations.1.note', 'Mika')
        ->where('invitations.1.created_by', 'Alex')
        ->missing('invitations.0.token_hash'));
});

it('lists no invitations for an account that may view users but not invite', function () {
    $viewers = Role::create(['name' => 'viewers', 'guard_name' => Roles::GUARD]);
    $viewers->givePermissionTo(Permission::UsersView->value);
    $viewer = User::factory()->withRole('viewers')->create();
    app(InvitationService::class)->issue(Actor::console());

    $this->actingAs($viewer)->get('/users')->assertInertia(fn (AssertableInertia $page) => $page->where('invitations', []));
});
