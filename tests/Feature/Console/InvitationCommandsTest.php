<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Authorization\Roles;
use App\Invitations\Invitation;
use App\Invitations\InvitationKind;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/*
| tesserarr:invite, tesserarr:invite:revoke and tesserarr:invite:recovery: the
| console issues an invitation (the first one included, there is no bootstrap
| endpoint), prints its link once, revokes it, and issues a recovery
| invitation for an account named by its username.
*/

beforeEach(function () {
    $this->base = (int) (AuditEntry::query()->max('id') ?? 0);
});

it('issues an invitation, prints its link once and logs it from the console', function () {
    $this->freezeSecond();
    $exit = Artisan::call('tesserarr:invite', ['--note' => 'For Sam']);
    $output = Artisan::output();

    $invitation = Invitation::query()->sole();
    preg_match('~^'.preg_quote(url('/invite'), '~').'#([A-Za-z0-9_-]{43})$~m', $output, $match);

    expect($exit)->toBe(0)
        ->and($match)->toHaveCount(2)
        ->and(substr_count($output, $match[1]))->toBe(1)
        ->and(hash('sha256', $match[1]))->toBe($invitation->token_hash)
        ->and($invitation->kind)->toBe(InvitationKind::Access)
        ->and($invitation->note)->toBe('For Sam')
        ->and($invitation->expires_at->equalTo(now()->addDays(7)))->toBeTrue()
        ->and($output)->toContain("Invitation {$invitation->id}");

    $entry = AuditEntry::query()->where('id', '>', $this->base)->where('action', AuditAction::InvitationIssued->value)->sole();
    expect($entry->actor_type)->toBe('cli')
        ->and($entry->subject_type)->toBe('invitation')
        ->and($entry->subject_id)->toBe($invitation->id);
});

it('takes --days and refuses a value out of range', function () {
    $this->freezeSecond();
    $this->artisan('tesserarr:invite', ['--days' => '1'])->assertSuccessful();
    expect(Invitation::query()->sole()->expires_at->equalTo(now()->addDay()))->toBeTrue();

    foreach (['0', '91', 'seven', '-3'] as $days) {
        $this->artisan('tesserarr:invite', ['--days' => $days])->assertFailed();
    }
    $this->artisan('tesserarr:invite', ['--note' => str_repeat('n', 101)])->assertFailed();

    expect(Invitation::query()->count())->toBe(1);
});

it('revokes an open invitation and refuses an unknown or used one', function () {
    Artisan::call('tesserarr:invite');
    $id = Invitation::query()->sole()->id;

    $this->artisan('tesserarr:invite:revoke', ['id' => (string) $id])->assertSuccessful();
    expect(Invitation::query()->sole()->revoked_at)->not->toBeNull();

    $this->artisan('tesserarr:invite:revoke', ['id' => (string) $id])->assertFailed();
    $this->artisan('tesserarr:invite:revoke', ['id' => '99999'])->assertFailed();
    $this->artisan('tesserarr:invite:revoke', ['id' => 'abc'])->assertFailed();

    expect(AuditEntry::query()->where('id', '>', $this->base)->where('action', AuditAction::InvitationRevoked->value)->count())->toBe(1);
});

it('issues a recovery invitation for an account named by its username', function () {
    $sam = User::factory()->withRole(Roles::USER)->create(['username' => 'sam']);

    $exit = Artisan::call('tesserarr:invite:recovery', ['username' => 'Sam', '--days' => '2']);
    $output = Artisan::output();

    $invitation = Invitation::query()->sole();
    expect($exit)->toBe(0)
        ->and($invitation->kind)->toBe(InvitationKind::Recovery)
        ->and($invitation->for_user_id)->toBe($sam->id)
        ->and($output)->toContain("sam (id {$sam->id})")
        ->and($output)->toContain(url('/invite').'#');

    $this->artisan('tesserarr:invite:recovery', ['username' => 'nobody'])->assertFailed();
    $this->artisan('tesserarr:invite:recovery', ['username' => 'a@b.example.org'])->assertFailed();
    expect(Invitation::query()->count())->toBe(1);
});
