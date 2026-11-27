<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Authorization\Roles;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;

/*
| tesserarr:token:issue and tesserarr:token:revoke: the console issues a token
| for a named account, capped at what that account holds, prints it once and
| logs the action with the console as origin.
*/

beforeEach(function () {
    $this->bob = User::factory()->withRole(Roles::USER)->create(['username' => 'bob']);
});

it('issues a token for an account, prints it once and logs it', function () {
    $this->freezeTime();
    $exit = Artisan::call('tesserarr:token:issue', ['username' => 'bob', '--name' => 'Sonarr', '--permission' => ['catalog.browse'], '--expires' => '30']);
    $output = Artisan::output();

    $token = PersonalAccessToken::query()->sole();
    preg_match('/^\d+\|tsr_\S+$/m', $output, $match);

    expect($exit)->toBe(0)
        ->and($match)->toHaveCount(1)
        ->and(substr_count($output, $match[0]))->toBe(1)
        ->and(hash('sha256', explode('|', $match[0], 2)[1]))->toBe($token->getAttributes()['token'])
        ->and($token->tokenable_id)->toBe($this->bob->id)
        ->and($token->abilities)->toBe(['catalog.browse'])
        ->and($token->expires_at?->toIso8601String())->toBe(now()->addDays(30)->toIso8601String())
        ->and($output)->toContain("bob (id {$this->bob->id})");

    $entry = AuditEntry::query()->where('action', AuditAction::TokenIssued->value)->sole();
    expect($entry->actor_type)->toBe('cli')
        ->and($entry->subject_id)->toBe($token->id);
});

it('issues a preset and lasts 90 days unless told otherwise', function () {
    $this->freezeTime();
    $this->artisan('tesserarr:token:issue', ['username' => 'bob', '--name' => 'arr', '--preset' => 'arr'])->assertSuccessful();

    $token = PersonalAccessToken::query()->sole();
    expect($token->abilities)->toBe(['catalog.browse'])
        ->and($token->expires_at?->toIso8601String())->toBe(now()->addDays(90)->toIso8601String());
});

it('issues a token that never expires with --expires=never', function () {
    $this->artisan('tesserarr:token:issue', ['username' => 'bob', '--name' => 'n', '--permission' => ['catalog.browse'], '--expires' => 'never'])->assertSuccessful();

    expect(PersonalAccessToken::query()->sole()->expires_at)->toBeNull();
});

it('caps a console token at what the account holds', function () {
    $this->artisan('tesserarr:token:issue', ['username' => 'bob', '--name' => 'x', '--permission' => ['users.manage']])
        ->expectsOutputToContain('users.manage')
        ->assertFailed();

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', AuditAction::TokenIssued->value)->where('outcome', 'denied')->count())->toBe(1);
});

it('refuses satellite.execute, unknown permissions and presets, a missing name and an unknown account', function (array $arguments, string $message) {
    $this->artisan('tesserarr:token:issue', ['username' => 'bob', '--name' => 'x', ...$arguments])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(PersonalAccessToken::query()->count())->toBe(0);
})->with([
    'satellite' => [['--permission' => ['satellite.execute']], 'satellite.execute'],
    'unknown permission' => [['--permission' => ['everything']], 'Unknown permission "everything"'],
    'unknown preset' => [['--preset' => 'satellite'], 'Unknown preset "satellite"'],
    'nothing' => [[], 'Name a permission'],
    'bad expiry' => [['--permission' => ['catalog.browse'], '--expires' => 'soon'], '--expires'],
    'no name' => [['--name' => '', '--permission' => ['catalog.browse']], '--name'],
    'unknown account' => [['username' => 'carol', '--permission' => ['catalog.browse']], 'No account with the username "carol"'],
]);

it('revokes a token by id and logs it', function () {
    $token = $this->bob->createToken('old', ['catalog.browse'])->accessToken;

    $this->artisan('tesserarr:token:revoke', ['id' => (string) $token->id])
        ->expectsOutputToContain("Token {$token->id}")
        ->assertSuccessful();

    expect(PersonalAccessToken::query()->find($token->id))->toBeNull()
        ->and(AuditEntry::query()->where('action', AuditAction::TokenRevoked->value)->sole()->actor_type)->toBe('cli');
});

it('refuses to revoke a token that does not exist', function (string $id) {
    $this->artisan('tesserarr:token:revoke', ['id' => $id])->expectsOutputToContain('No token')->assertFailed();
})->with(['999999', 'abc', '-1']);
