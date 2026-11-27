<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Invitations\InvitationKind;
use App\Invitations\InvitationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
| The page behind an invitation link (Invite-Phone.dc.html). The token is in
| the fragment, so the GET never carries it; the page posts it to see who
| invites. Looking never uses the invitation up, and every reason it cannot
| be used gets the same answer.
*/

beforeEach(function () {
    $this->operator = User::factory()->operator()->create(['username' => 'alex', 'display_name' => 'Alex']);
});

it('shows the page without any invitation data on the GET', function () {
    $this->get('/invite')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/Redeem')->where('invitation', null)->where('methods', []));
});

it('shows who invites and until when, and uses nothing up', function () {
    $issued = app(InvitationService::class)->issue(Actor::user($this->operator));

    $this->post('/invite', ['token' => $issued->plainToken])->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Redeem')
            ->where('invitation.kind', 'access')
            ->where('invitation.inviter', 'Alex')
            ->where('invitation.expires_at', $issued->invitation->expires_at->toIso8601String())
            ->where('invitation.account', null));

    expect($issued->invitation->fresh()?->redeemed_at)->toBeNull()
        ->and($issued->invitation->fresh()?->reservation_count)->toBe(0);
});

it('names the account of a recovery invitation and no inviter for one from the console', function () {
    $sam = User::factory()->create(['username' => 'sam']);
    $issued = app(InvitationService::class)->issue(Actor::console(), InvitationKind::Recovery, $sam);

    $this->post('/invite', ['token' => $issued->plainToken])->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('invitation.kind', 'recovery')->where('invitation.account', 'sam')->where('invitation.inviter', null));
});

it('answers every unusable invitation with the same message', function () {
    $service = app(InvitationService::class);
    $revoked = $service->issue(Actor::console());
    $service->revoke($revoked->invitation->id, Actor::console());
    $expired = $service->issue(Actor::console());
    DB::table('invitations')->where('id', $expired->invitation->id)->update(['expires_at' => now()->subMinute()]);

    foreach ([$revoked->plainToken, $expired->plainToken, str_repeat('A', 43), 'not a token'] as $token) {
        $this->from('/invite')->post('/invite', ['token' => $token])->assertRedirect('/invite')
            ->assertSessionHasErrors(['token' => __('invitations.redeem.not_redeemable')]);
        // The token is never flashed back into the session.
        expect(json_encode(session()->all()))->not->toContain($token);
    }
});

it('limits how often the page can look an invitation up', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->post('/invite', ['token' => str_repeat('B', 43)]);
    }

    $this->post('/invite', ['token' => str_repeat('B', 43)])->assertStatus(429);
});
