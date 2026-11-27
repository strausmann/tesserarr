<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Authorization\Roles;
use App\Identity\Identity;
use App\Mail\ConfirmEmailMail;
use App\Mail\EmailChangeRequestedMail;
use App\Models\User;
use App\Privacy\AccountEmailPolicy;
use App\Privacy\BlindIndex;
use App\Privacy\EmailAlreadyClaimed;
use App\Privacy\EmailClaimThrottled;
use App\Privacy\EmailConfirmation;
use App\Privacy\EmailEntryDisabled;
use App\Privacy\InvalidConfirmationLink;
use App\Privacy\PiiKeys;
use App\Settings\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/*
| The account address (task 8a, Spec 16.16): optional, unique across accounts,
| valid only once confirmed by a single-use link, reserved for 24 hours at
| most while unconfirmed. The address a provider reports stays on the identity
| and is not unique.
*/

beforeEach(function () {
    Mail::fake();
    app(Settings::class)->set(AccountEmailPolicy::SETTING, true, Actor::cli('test'));
});

function confirmation(): EmailConfirmation
{
    return app(EmailConfirmation::class);
}

/** Requests $email for $user as the user and returns the token of the link sent. */
function requestAndCatchToken(User $user, string $email, ?Actor $by = null): string
{
    confirmation()->request($user, $email, $by ?? Actor::user($user));
    $token = null;
    Mail::assertQueued(ConfirmEmailMail::class, function (ConfirmEmailMail $mail) use ($email, &$token) {
        if (! $mail->hasTo($email)) {
            return false;
        }
        $token = substr($mail->url, (int) strrpos($mail->url, '#') + 1);

        return true;
    });

    return (string) $token;
}

it('finds accounts by email through the blind index regardless of case, spaces and unicode form', function () {
    $user = User::factory()->withConfirmedEmail("j\u{00E9}r\u{00F4}me@b\u{00FC}cher.test")->create();

    expect(confirmation()->confirmedOwnerOf(" J\u{0065}\u{0301}R\u{00D4}ME@B\u{00DC}CHER.test ")?->id)->toBe($user->id)
        ->and(confirmation()->confirmedOwnerOf('jerome@bucher.test'))->toBeNull();
});

it('rejects a second account with the same account address with an understandable message', function () {
    $alice = User::factory()->withConfirmedEmail('shared@example.org')->create(['username' => 'alice']);
    $bob = User::factory()->create(['username' => 'bob']);

    $this->actingAs($bob)->from('/account')->put('/account/email', ['email' => 'Shared@Example.org'])
        ->assertRedirect('/account')
        ->assertSessionHasErrors(['email' => 'This address is already the address of another account.']);

    $message = session('errors')->first('email');
    expect($message)->not->toContain('alice')
        ->and(DB::table('account_email_claims')->where('user_id', $bob->id)->exists())->toBeFalse()
        ->and($bob->refresh()->pending_email)->toBeNull();
    Mail::assertNothingQueued();
});

it('refuses the address in the service with EmailAlreadyClaimed, also while it is only reserved', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    confirmation()->request($alice, 'reserved@example.org', Actor::user($alice));

    expect(fn () => confirmation()->request($bob, 'reserved@example.org', Actor::user($bob)))->toThrow(EmailAlreadyClaimed::class);
});

it('allows several accounts without an account address', function () {
    User::factory()->count(3)->create();

    expect(DB::table('users')->whereNull('email')->whereNull('email_bidx')->count())->toBe(3);
});

it('allows the same provider address on identities of two accounts', function () {
    [$a, $b] = User::factory()->count(2)->create();
    foreach ([$a, $b] as $i => $user) {
        Identity::query()->create(['user_id' => $user->id, 'method' => 'oidc', 'provider_key' => 'example', 'subject' => "s-{$i}", 'email' => 'same@idp.example.net']);
    }

    expect(DB::table('identities')->where('email_bidx', BlindIndex::of('same@idp.example.net'))->count())->toBe(2)
        ->and(DB::table('users')->whereNotNull('email')->count())->toBe(0);
});

it('ignores email fields in mass assignment', function () {
    $user = User::factory()->withConfirmedEmail('kept@example.org')->create();

    $user->update([
        'display_name' => 'Renamed',
        'email' => 'other@example.org',
        'email_bidx' => str_repeat('0', 64),
        'email_confirmed_at' => null,
        'pending_email' => 'other@example.org',
    ]);

    $user->refresh();
    expect($user->display_name)->toBe('Renamed')
        ->and($user->email)->toBe('kept@example.org')
        ->and($user->email_bidx)->toBe(BlindIndex::of('kept@example.org'))
        ->and($user->email_confirmed_at)->not->toBeNull()
        ->and($user->pending_email)->toBeNull();
});

it('never writes users.email during the password sign-in', function () {
    $user = User::factory()->create(['username' => 'alice']);
    $before = DB::table('users')->where('id', $user->id)->first(['email', 'email_bidx', 'pending_email']);

    $this->post('/login', ['login' => 'alice', 'password' => 'password'])->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect(DB::table('users')->where('id', $user->id)->first(['email', 'email_bidx', 'pending_email']))->toEqual($before);
});

it('never writes users.email during sign-in or redemption', function (string $flow) {
    // Structurally covered for every flow by the PHPStan rule
    // OnlyEmailConfirmationWritesAccountEmail; the flows themselves arrive with
    // their tasks, which turn these cases into running tests.
})->with([
    'redeem via oidc', 'redeem via plex', 'redeem via header', 'redeem via password', 'return via oidc', 'return via plex', 'return via header',
])->todo(note: 'Carried over: redemption to task 10, OIDC to task 13, Plex to task 14, header to task 15 (P17).');

it('offers the provider address as a suggestion when the account address is empty', function () {
    $user = User::factory()->create();
    Identity::query()->create(['user_id' => $user->id, 'method' => 'oidc', 'provider_key' => 'example', 'subject' => 's-1', 'email' => 'me@idp.example.net']);

    $this->actingAs($user)->get('/account')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('account/Index')
            ->where('suggestion', 'me@idp.example.net')
            ->where('email.confirmed', null)
            ->where('identities.0.email', 'me@idp.example.net'));

    $confirmed = User::factory()->withConfirmedEmail('mine@example.org')->create();
    Identity::query()->create(['user_id' => $confirmed->id, 'method' => 'oidc', 'provider_key' => 'example', 'subject' => 's-2', 'email' => 'me2@idp.example.net']);
    $this->actingAs($confirmed)->get('/account')
        ->assertInertia(fn (Assert $page) => $page->where('suggestion', null));
});

it('does not accept an unconfirmed account address as sign-in name or for mail delivery', function () {
    $user = User::factory()->create();
    confirmation()->request($user, 'waiting@example.org', Actor::user($user));
    $user->refresh();

    expect($user->pending_email)->toBe('waiting@example.org')
        ->and($user->email)->toBeNull()
        ->and(confirmation()->confirmedOwnerOf('waiting@example.org'))->toBeNull()
        ->and($user->routeNotificationForMail())->toBeNull();
});

it('finds no account by an address that is in email but not confirmed', function () {
    // An address the migration of an earlier release candidate kept without a
    // confirmation, or any row written past EmailConfirmation: not a sign-in name.
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['email_bidx' => BlindIndex::of('legacy@example.org')]);

    expect(confirmation()->confirmedOwnerOf('legacy@example.org'))->toBeNull();
});

it('confirms an account address with a single-use link and rejects the link a second time', function () {
    $user = User::factory()->create();
    $token = requestAndCatchToken($user, 'Mine@Example.org');

    $this->get('/email/confirm')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/ConfirmEmail'));
    // Opening the link alone changes nothing (a mail scanner fetches it too).
    expect($user->refresh()->email)->toBeNull();

    $this->post('/email/confirm', ['token' => $token])->assertRedirect('/login');

    $user->refresh();
    expect($user->email)->toBe('Mine@Example.org')
        ->and($user->email_confirmed_at)->not->toBeNull()
        ->and($user->pending_email)->toBeNull()
        ->and(confirmation()->confirmedOwnerOf('mine@example.org')?->id)->toBe($user->id)
        ->and(DB::table('account_email_claims')->where('user_id', $user->id)->pluck('kind')->all())->toBe(['confirmed'])
        ->and(AuditEntry::query()->where('action', AuditAction::EmailConfirmed->value)->where('subject_id', $user->id)->exists())->toBeTrue();

    $this->from('/email/confirm')->post('/email/confirm', ['token' => $token])
        ->assertSessionHasErrors(['token' => 'This link is not valid (any more). Ask for a new one under "My account".']);
    expect(fn () => confirmation()->confirm($token))->toThrow(InvalidConfirmationLink::class);
});

it('sends a signed-in account back to its page after confirming', function () {
    $user = User::factory()->create();
    $token = requestAndCatchToken($user, 'mine@example.org');

    $this->actingAs($user)->post('/email/confirm', ['token' => $token])->assertRedirect('/account');
});

it('rejects an expired confirmation link after 60 minutes', function () {
    $user = User::factory()->create();
    $token = requestAndCatchToken($user, 'late@example.org');

    $this->travel(EmailConfirmation::LINK_MINUTES)->minutes();
    $this->travel(1)->seconds();

    expect(fn () => confirmation()->confirm($token))->toThrow(InvalidConfirmationLink::class)
        ->and($user->refresh()->email)->toBeNull();
});

it('accepts the link just before its 60 minutes run out', function () {
    $user = User::factory()->create();
    $token = requestAndCatchToken($user, 'intime@example.org');

    $this->travel(EmailConfirmation::LINK_MINUTES - 1)->minutes();

    expect(confirmation()->confirm($token)->email)->toBe('intime@example.org');
});

it('rejects a link of an address that was replaced by a newer request', function () {
    $user = User::factory()->create();
    $first = requestAndCatchToken($user, 'first@example.org');
    requestAndCatchToken($user, 'second@example.org');

    expect(fn () => confirmation()->confirm($first))->toThrow(InvalidConfirmationLink::class)
        ->and(DB::table('account_email_claims')->where('bidx', BlindIndex::of('first@example.org'))->exists())->toBeFalse();
});

it('releases an unconfirmed address after 24 hours so another account can claim it', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    confirmation()->request($alice, 'squat@example.org', Actor::user($alice));

    $this->travel(EmailConfirmation::RESERVATION_HOURS)->hours();
    $this->travel(-1)->minutes();
    expect(fn () => confirmation()->request($bob, 'squat@example.org', Actor::user($bob)))->toThrow(EmailAlreadyClaimed::class);

    // The hourly task frees it once the 24 hours are over.
    $this->travel(2)->minutes();
    $this->artisan('tesserarr:email:release-expired')->expectsOutputToContain('1 reservation(s) released')->assertSuccessful();
    expect($alice->refresh()->pending_email)->toBeNull();

    confirmation()->request($bob, 'squat@example.org', Actor::user($bob));
    expect($bob->refresh()->pending_email)->toBe('squat@example.org');
});

it('frees an expired foreign reservation of the same address within the request', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    confirmation()->request($alice, 'squat@example.org', Actor::user($alice));
    $this->travel(EmailConfirmation::RESERVATION_HOURS + 1)->hours();

    // No hourly run in between: the request itself clears the stale reservation.
    confirmation()->request($bob, 'squat@example.org', Actor::user($bob));

    expect($bob->refresh()->pending_email)->toBe('squat@example.org')
        ->and($alice->refresh()->pending_email)->toBeNull()
        ->and(DB::table('account_email_claims')->where('bidx', BlindIndex::of('squat@example.org'))->value('user_id'))->toBe($bob->id);
});

it('runs the release of expired reservations every hour', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'tesserarr:email:release-expired'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 * * * *');
});

it('lets an operator release a reserved address at once', function () {
    $operator = User::factory()->operator()->create();
    $alice = User::factory()->withRole(Roles::USER)->create();
    $bob = User::factory()->withRole(Roles::USER)->create();
    confirmation()->request($alice, 'held@example.org', Actor::user($alice));

    $this->actingAs($operator)->delete("/users/{$alice->id}/email/pending")->assertRedirect();

    expect($alice->refresh()->pending_email)->toBeNull()
        ->and(AuditEntry::query()->where('action', AuditAction::EmailReservationReleased->value)
            ->where('actor_id', $operator->id)->where('subject_id', $alice->id)->exists())->toBeTrue();
    confirmation()->request($bob, 'held@example.org', Actor::user($bob));
    expect($bob->refresh()->pending_email)->toBe('held@example.org');
});

it('refuses the release to a user without users.manage', function () {
    $friend = User::factory()->withRole(Roles::USER)->create();
    $alice = User::factory()->withRole(Roles::USER)->create();
    confirmation()->request($alice, 'held@example.org', Actor::user($alice));

    $this->actingAs($friend)->deleteJson("/users/{$alice->id}/email/pending")->assertForbidden();
    expect($alice->refresh()->pending_email)->toBe('held@example.org');
});

it('disables entering an account address when the operator switch is off or no transport exists', function (Closure $setUp, string $reason, string $message) {
    $user = User::factory()->create();
    $setUp();

    expect(fn () => confirmation()->request($user, 'a@example.org', Actor::user($user)))
        ->toThrow(fn (EmailEntryDisabled $e) => expect($e->reason)->toBe($reason));

    $this->actingAs($user)->get('/account')
        ->assertInertia(fn (Assert $page) => $page->where('email.entry.allowed', false)->where('email.entry.reason', $reason));
    $this->actingAs($user)->from('/account')->put('/account/email', ['email' => 'a@example.org'])
        ->assertSessionHasErrors(['email' => $message]);
    expect(DB::table('account_email_claims')->count())->toBe(0);
    Mail::assertNothingQueued();
})->with([
    'switch off' => [fn () => app(Settings::class)->set(AccountEmailPolicy::SETTING, false, Actor::cli('test')), 'switched_off', 'The operator has not allowed account addresses. You sign in with your username.'],
    'no mailer' => [fn () => config(['mail.default' => null]), 'no_transport', 'No mail can be sent yet, so no address can be confirmed. You sign in with your username.'],
    'log mailer' => [fn () => config(['mail.default' => 'log', 'mail.mailers.log' => ['transport' => 'log']]), 'no_transport', 'No mail can be sent yet, so no address can be confirmed. You sign in with your username.'],
]);

it('defines no log mailer outside the local environment', function () {
    $config = fn (string $env) => (function () use ($env) {
        $previous = getenv('APP_ENV');
        putenv("APP_ENV={$env}");
        $_ENV['APP_ENV'] = $env;
        try {
            return require config_path('mail.php');
        } finally {
            putenv('APP_ENV='.$previous);
            $_ENV['APP_ENV'] = $previous;
        }
    })();

    expect($config('production')['mailers'])->not->toHaveKey('log')
        ->and($config('production')['mailers']['failover']['mailers'])->not->toContain('log')
        ->and($config('local')['mailers'])->toHaveKey('log');
});

it('keeps the confirmed old address valid for sign-in and mail until the new one is confirmed', function () {
    $operator = User::factory()->operator()->create();
    $user = User::factory()->withRole(Roles::USER)->withConfirmedEmail('old@example.org')->create();

    $this->actingAs($operator)->put("/users/{$user->id}/email", ['email' => 'new@example.org'])->assertRedirect();

    $user->refresh();
    expect($user->confirmedEmail())->toBe('old@example.org')
        ->and($user->routeNotificationForMail())->toBe('old@example.org')
        ->and(confirmation()->confirmedOwnerOf('old@example.org')?->id)->toBe($user->id)
        ->and(confirmation()->confirmedOwnerOf('new@example.org'))->toBeNull()
        ->and($user->pending_email)->toBe('new@example.org');

    $token = null;
    Mail::assertQueued(ConfirmEmailMail::class, function (ConfirmEmailMail $mail) use (&$token) {
        $token = substr($mail->url, (int) strrpos($mail->url, '#') + 1);

        return $mail->hasTo('new@example.org');
    });
    confirmation()->confirm((string) $token);

    expect(confirmation()->confirmedOwnerOf('new@example.org')?->id)->toBe($user->id)
        ->and(confirmation()->confirmedOwnerOf('old@example.org'))->toBeNull()
        // The old address is free again for another account.
        ->and(DB::table('account_email_claims')->where('bidx', BlindIndex::of('old@example.org'))->exists())->toBeFalse();
});

it('notifies the old address when a change is requested', function () {
    $operator = User::factory()->operator()->create();
    $user = User::factory()->withRole(Roles::USER)->withConfirmedEmail('old@example.org')->create();

    $this->actingAs($operator)->put("/users/{$user->id}/email", ['email' => 'new@example.org'])->assertRedirect();

    Mail::assertQueued(EmailChangeRequestedMail::class, fn (EmailChangeRequestedMail $m) => $m->hasTo('old@example.org') && ! $m->hasTo('new@example.org'));
    Mail::assertQueued(ConfirmEmailMail::class, 1);
});

it('sends no change notice for a first address', function () {
    $user = User::factory()->create();
    confirmation()->request($user, 'first@example.org', Actor::user($user));

    Mail::assertNotQueued(EmailChangeRequestedMail::class);
});

it('lets an account enter a first address but not change a confirmed one yet', function () {
    $user = User::factory()->withConfirmedEmail('mine@example.org')->create();

    $this->actingAs($user)->from('/account')->put('/account/email', ['email' => 'other@example.org'])
        ->assertSessionHasErrors(['email' => 'Changing a confirmed address needs a confirmation with your second factor, which arrives with a later version.']);
    expect($user->refresh()->pending_email)->toBeNull();
    Mail::assertNothingQueued();
});

it('refuses an operator changing their own confirmed address through the user page', function () {
    $operator = User::factory()->operator()->withConfirmedEmail('me@example.org')->create();

    $this->actingAs($operator)->putJson("/users/{$operator->id}/email", ['email' => 'other@example.org'])
        ->assertStatus(409)->assertJsonPath('error', 'own_address_needs_confirmation');
    expect($operator->refresh()->pending_email)->toBeNull()
        ->and(DB::table('account_email_claims')->where('user_id', $operator->id)->pluck('kind')->all())->toBe(['confirmed']);
    Mail::assertNothingQueued();

    // On every path, not only these two pages: the check is in the service.
    expect(fn () => confirmation()->request($operator, 'other@example.org', Actor::user($operator)))
        ->toThrow(fn (EmailEntryDisabled $e) => expect($e->reason)->toBe('confirmed_exists'));
});

it('lets an operator enter a first address for themselves and change somebody else’s', function () {
    $operator = User::factory()->operator()->create();
    $other = User::factory()->withRole(Roles::USER)->withConfirmedEmail('old@example.org')->create();

    $this->actingAs($operator)->put("/users/{$operator->id}/email", ['email' => 'me@example.org'])->assertSessionHasNoErrors();
    $this->actingAs($operator)->put("/users/{$other->id}/email", ['email' => 'new@example.org'])->assertSessionHasNoErrors();

    expect($operator->refresh()->pending_email)->toBe('me@example.org')
        ->and($other->refresh()->pending_email)->toBe('new@example.org');
});

it('builds the confirmation link from APP_URL, not from the Host header of the request', function () {
    config(['app.url' => 'https://tesserarr.example.org']);
    $user = User::factory()->create();

    // The test request takes its Host header from the URL.
    $this->actingAs($user)->put('http://attacker.example.net/account/email', ['email' => 'mine@example.org'])->assertSessionHasNoErrors();
    expect(request()->getHost())->toBe('attacker.example.net');

    Mail::assertQueued(ConfirmEmailMail::class, fn (ConfirmEmailMail $mail) => str_starts_with($mail->url, 'https://tesserarr.example.org/email/confirm#')
        && ! str_contains($mail->url, 'attacker'));
});

it('keeps an address unique and findable while the blind index key changes', function () {
    $alice = User::factory()->withConfirmedEmail('shared@example.org')->create();
    $bob = User::factory()->create();

    // Restarted with a new blind index key, the rotation has not run yet:
    // Alice's claim still carries the index under the old key.
    $old = (string) config('tesserarr.pii.blind_index_key');
    config(['tesserarr.pii.blind_index_key' => str_repeat('n', 32), 'tesserarr.pii.blind_index_previous_key' => $old]);
    app()->forgetInstance(PiiKeys::class);

    expect(fn () => confirmation()->request($bob, 'Shared@Example.org', Actor::user($bob)))->toThrow(EmailAlreadyClaimed::class)
        ->and(confirmation()->confirmedOwnerOf('shared@example.org')?->id)->toBe($alice->id)
        ->and(DB::table('account_email_claims')->count())->toBe(1);

    $this->artisan('tesserarr:pii:rotate')->assertSuccessful();
    expect(DB::table('account_email_claims')->value('bidx'))->toBe(BlindIndex::with('shared@example.org', str_repeat('n', 32)));
});

it('frees an expired reservation under the previous blind index key when the address is claimed again', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    confirmation()->request($alice, 'squat@example.org', Actor::user($alice));
    $old = (string) config('tesserarr.pii.blind_index_key');

    $this->travel(EmailConfirmation::RESERVATION_HOURS + 1)->hours();
    config(['tesserarr.pii.blind_index_key' => str_repeat('n', 32), 'tesserarr.pii.blind_index_previous_key' => $old]);
    app()->forgetInstance(PiiKeys::class);

    confirmation()->request($bob, 'squat@example.org', Actor::user($bob));

    expect(DB::table('account_email_claims')->pluck('user_id')->all())->toBe([$bob->id])
        ->and($alice->refresh()->pending_email)->toBeNull();
    $this->artisan('tesserarr:pii:rotate')->assertSuccessful();
});

it('recognises the confirmed address under the previous blind index key when it is entered again', function () {
    $operator = User::factory()->operator()->create();
    $alice = User::factory()->withRole(Roles::USER)->withConfirmedEmail('alice@example.org')->create();
    $old = (string) config('tesserarr.pii.blind_index_key');
    config(['tesserarr.pii.blind_index_key' => str_repeat('n', 32), 'tesserarr.pii.blind_index_previous_key' => $old]);
    app()->forgetInstance(PiiKeys::class);

    $this->actingAs($operator)->put("/users/{$alice->id}/email", ['email' => 'Alice@Example.org'])->assertSessionHasNoErrors();

    expect($alice->refresh()->pending_email)->toBeNull()
        ->and(DB::table('account_email_claims')->pluck('kind')->all())->toBe(['confirmed']);
    Mail::assertNothingQueued();
    $this->artisan('tesserarr:pii:rotate')->assertSuccessful();
});

it('lets the account send the link of an address an operator entered again, also with a confirmed one', function () {
    $operator = User::factory()->operator()->create();
    $user = User::factory()->withRole(Roles::USER)->withConfirmedEmail('old@example.org')->create();
    $this->actingAs($operator)->put("/users/{$user->id}/email", ['email' => 'new@example.org'])->assertSessionHasNoErrors();

    $this->actingAs($user)->from('/account')->post('/account/email/resend')->assertSessionHasNoErrors();

    Mail::assertQueued(ConfirmEmailMail::class, 2);
    expect($user->refresh()->pending_email)->toBe('new@example.org')
        ->and($user->confirmedEmail())->toBe('old@example.org');
    // A different address is still refused.
    expect(fn () => confirmation()->request($user, 'third@example.org', Actor::user($user)))
        ->toThrow(fn (EmailEntryDisabled $e) => expect($e->reason)->toBe('confirmed_exists'));
});

it('keeps a waiting reservation and its link working while the blind index key changes', function () {
    $operator = User::factory()->operator()->create();
    $user = User::factory()->withRole(Roles::USER)->withConfirmedEmail('old@example.org')->create();
    $fresh = User::factory()->withRole(Roles::USER)->create();
    $this->actingAs($operator)->put("/users/{$user->id}/email", ['email' => 'new@example.org'])->assertSessionHasNoErrors();
    confirmation()->request($fresh, 'first@example.org', Actor::user($fresh));
    $until = DB::table('users')->where('id', $fresh->id)->value('pending_email_reserved_until');

    // Restarted with a new blind index key, the rotation has not run yet.
    $old = (string) config('tesserarr.pii.blind_index_key');
    config(['tesserarr.pii.blind_index_key' => str_repeat('n', 32), 'tesserarr.pii.blind_index_previous_key' => $old]);
    app()->forgetInstance(PiiKeys::class);
    $this->travel(5)->minutes();

    // With a confirmed address: the resend is the own reservation, not a change.
    $this->actingAs($user)->from('/account')->post('/account/email/resend')->assertSessionHasNoErrors();
    // Without one: the reservation is kept, not dropped and made again.
    confirmation()->request($fresh, 'first@example.org', Actor::user($fresh));

    expect(DB::table('users')->where('id', $fresh->id)->value('pending_email_reserved_until'))->toBe($until)
        ->and(DB::table('account_email_claims')->where('kind', 'pending')->count())->toBe(2);

    // The newest link of each still confirms its address.
    $links = [];
    Mail::assertQueued(ConfirmEmailMail::class, function (ConfirmEmailMail $mail) use (&$links) {
        $links[] = substr($mail->url, strrpos($mail->url, '#') + 1);

        return true;
    });
    confirmation()->confirm($links[2]);
    confirmation()->confirm($links[3]);
    expect($user->refresh()->confirmedEmail())->toBe('new@example.org')
        ->and($fresh->refresh()->confirmedEmail())->toBe('first@example.org');

    $this->artisan('tesserarr:pii:rotate')->assertSuccessful();
    expect(confirmation()->confirmedOwnerOf('new@example.org')?->id)->toBe($user->id);
});

it('requires a fresh proof to request an address change')
    ->todo(note: 'Carried over to task 11: ConfirmationProof and the change of a confirmed own address arrive there (pre-flight K14). Until then AccountController offers the first address only (test above).');

it('refuses an operator who lacks a permission of the account', function () {
    $manager = User::factory()->withRole(Roles::USER)->create();
    $role = Role::query()->create(['name' => 'manager', 'guard_name' => Roles::GUARD]);
    $role->givePermissionTo(['users.view', 'users.manage']);
    $manager->syncRoles([$role]);
    $operator = User::factory()->operator()->create();

    $this->actingAs($manager)->putJson("/users/{$operator->id}/email", ['email' => 'hijack@example.org'])
        ->assertForbidden()->assertJsonPath('error', 'privilege_escalation');
    expect($operator->refresh()->pending_email)->toBeNull()
        ->and(DB::table('account_email_claims')->count())->toBe(0);
});

it('throttles the "address already in use" answer to 10 per user and hour and logs each rejection', function () {
    $taken = User::factory()->withConfirmedEmail('taken@example.org')->create();
    $prober = User::factory()->create();

    for ($i = 0; $i < EmailConfirmation::CHECKS_PER_HOUR; $i++) {
        expect(fn () => confirmation()->request($prober, 'taken@example.org', Actor::user($prober)))->toThrow(EmailAlreadyClaimed::class);
    }

    // The eleventh check is not made at all.
    expect(fn () => confirmation()->request($prober, 'taken@example.org', Actor::user($prober)))->toThrow(EmailClaimThrottled::class);
    $this->actingAs($prober)->from('/account')->put('/account/email', ['email' => 'free@example.org'])
        ->assertSessionHasErrors(['email' => 'Too many addresses checked. Try again in 60 minutes.']);

    $rejected = AuditEntry::query()->where('action', AuditAction::EmailClaimRejected->value)->get();
    expect($rejected)->toHaveCount(EmailConfirmation::CHECKS_PER_HOUR)
        ->and($rejected->every(fn (AuditEntry $e) => $e->actor_id === $prober->id && $e->subject_id === $prober->id && $e->outcome === AuditOutcome::Denied && $e->context === []))->toBeTrue()
        ->and(DB::table('account_email_claims')->where('user_id', $prober->id)->exists())->toBeFalse();

    // An hour later the user may check again.
    $this->travel(61)->minutes();
    confirmation()->request($prober, 'free@example.org', Actor::user($prober));
    expect($prober->refresh()->pending_email)->toBe('free@example.org');
});

it('resends the link within the reservation without extending it', function () {
    $user = User::factory()->create();
    confirmation()->request($user, 'mine@example.org', Actor::user($user));
    $until = $user->refresh()->pending_email_reserved_until;

    $this->travel(2)->hours();
    $this->actingAs($user)->post('/account/email/resend')->assertRedirect();

    Mail::assertQueued(ConfirmEmailMail::class, 2);
    expect($user->refresh()->pending_email_reserved_until?->equalTo($until))->toBeTrue()
        ->and(DB::table('email_confirmations')->where('user_id', $user->id)->whereNull('used_at')->count())->toBe(1);
});

it('shows user A their own address and own activity but not user B’s address', function () {
    $a = User::factory()->withRole(Roles::USER)->withConfirmedEmail('alice@example.org')->create(['username' => 'alice']);
    $b = User::factory()->withRole(Roles::USER)->withConfirmedEmail('bob@example.org')->create(['username' => 'bob']);
    app(AuditLogger::class)->record(AuditAction::UserRenamed, Actor::user($a), $a, ['from' => 'al', 'to' => 'alice']);

    $this->actingAs($a)->get('/account')->assertOk()->assertSee('alice@example.org')->assertDontSee('bob@example.org')
        ->assertInertia(fn (Assert $page) => $page->where('email.confirmed', 'alice@example.org')
            ->where('activity.0.action', 'user.rename')->where('activity.0.who', $a->shownName()));
    $this->actingAs($b)->withHeader('X-Requested-With', 'tesserarr')->getJson('/api/v1/app/users/'.$a->id)->assertForbidden();
    $this->actingAs($b)->get('/account/activity')->assertOk()->assertDontSee('alice@example.org')
        ->assertInertia(fn (Assert $page) => $page->component('account/Activity')->where('entries', []));
    $this->actingAs($a)->get('/account/activity')
        ->assertInertia(fn (Assert $page) => $page->where('entries.0.action', 'user.rename'));
});

it('shows other users’ addresses only to holders of users.view', function () {
    $operator = User::factory()->operator()->create();
    $alice = User::factory()->withRole(Roles::USER)->withConfirmedEmail('alice@example.org')->create();
    $friend = User::factory()->withRole(Roles::USER)->create();

    $this->actingAs($operator)->withHeader('X-Requested-With', 'tesserarr')->getJson('/api/v1/app/users/'.$alice->id)->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json->where('email', 'alice@example.org')->where('email_pending', false)
            ->missing('email_bidx')->missing('pending_email')->missing('pgp_public_key')->etc());
    $this->actingAs($operator)->get("/users/{$alice->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/UserDetail')->where('email.confirmed', 'alice@example.org'));

    $this->actingAs($friend)->withHeader('X-Requested-With', 'tesserarr')->getJson('/api/v1/app/users/'.$alice->id)->assertForbidden();
    $this->actingAs($friend)->get("/users/{$alice->id}")->assertForbidden();
    $this->actingAs($friend)->get('/dashboard')->assertDontSee('alice@example.org');
});

it('never puts an address into the shared props of a page', function () {
    $user = User::factory()->withConfirmedEmail('alice@example.org')->create();
    confirmation()->request($user, 'next@example.org', Actor::cli('ops'));

    $this->actingAs($user)->get('/settings/profile')->assertOk()
        ->assertDontSee('alice@example.org')->assertDontSee('next@example.org')
        ->assertInertia(fn (Assert $page) => $page->missing('auth.user.email')->missing('auth.user.pending_email')->missing('auth.user.email_bidx'));
});
