<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Authorization\Roles;
use App\Identity\Identity;
use App\Mail\ConfirmEmailMail;
use App\Models\User;
use App\Privacy\AccountEmailPolicy;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
| After every flow of task 8a, no address is in plain text in any table, and
| none in the logs. The full search over every flow of the release (with
| invitations, mail delivery and sign-ins) is task 23a
| (tests/Privacy/PlaintextSweepTest.php, pre-flight H13); this one covers the
| tables of this task: users, identities, email_confirmations,
| account_email_claims, audit_entries — and every other table, which must
| not hold one either.
*/

/** Every row of every table of the public schema, as text, that contains $needle (any case). */
function tablesContaining(string $needle): array
{
    $tables = collect(DB::select("select tablename from pg_tables where schemaname = 'public'"))->pluck('tablename');
    $hits = [];
    foreach ($tables as $table) {
        $count = (int) DB::scalar("select count(*) from \"{$table}\" t where position(lower(?) in lower(t::text)) > 0", [$needle]);
        if ($count > 0) {
            $hits[] = "{$table} ({$count})";
        }
    }

    return $hits;
}

it('stores no address in plain text in the tables of this task', function () {
    $logs = storage_path('framework/testing/logs-'.Str::random(8));
    File::ensureDirectoryExists($logs);
    config(['logging.channels.single.path' => $logs.'/single.log', 'logging.default' => 'single']);
    app()->forgetInstance('log');
    Log::clearResolvedInstances();
    Mail::fake();
    app(Settings::class)->set(AccountEmailPolicy::SETTING, true, Actor::cli('test'));

    $tag = Str::lower(Str::random(6));
    $own = "own-{$tag}@example.invalid";
    $byOperator = "set-{$tag}@example.invalid";
    $changed = "changed-{$tag}@example.invalid";
    $provider = "idp-{$tag}@example.invalid";

    try {
        $operator = User::factory()->operator()->create();
        $alice = User::factory()->withRole(Roles::USER)->create();
        $bob = User::factory()->withRole(Roles::USER)->create();
        Identity::query()->create(['user_id' => $alice->id, 'method' => 'oidc', 'provider_key' => 'example', 'subject' => 's-1', 'email' => $provider]);
        $tokens = function () {
            $tokens = [];
            Mail::assertQueued(ConfirmEmailMail::class, function (ConfirmEmailMail $mail) use (&$tokens) {
                $tokens[$mail->to[0]['address']] = substr($mail->url, (int) strrpos($mail->url, '/') + 1);

                return true;
            });

            return $tokens;
        };

        // Alice enters her own address and confirms it; the operator sets
        // one for Bob, then replaces Alice's (old stays until confirmed);
        // Bob tries Alice's address in other letters and is refused; Alice opens her link.
        $this->actingAs($alice)->put('/account/email', ['email' => $own])->assertSessionHasNoErrors();
        $this->post('/email/confirm/'.$tokens()[$own])->assertRedirect();
        $this->actingAs($operator)->put("/users/{$bob->id}/email", ['email' => $byOperator])->assertSessionHasNoErrors();
        $this->actingAs($operator)->put("/users/{$alice->id}/email", ['email' => $changed])->assertSessionHasNoErrors();
        $this->actingAs($bob)->put('/account/email', ['email' => strtoupper($own)])->assertSessionHasErrors('email');
        $this->post('/email/confirm/'.$tokens()[$changed])->assertRedirect();
        Log::error("delivery to {$own} failed", ['to' => $changed]);

        // The flow really wrote every table it is about.
        foreach (['users' => 3, 'identities' => 1, 'email_confirmations' => 3, 'account_email_claims' => 2] as $table => $min) {
            expect(DB::table($table)->count())->toBeGreaterThanOrEqual($min, "{$table} was not written");
        }
        expect(DB::table('audit_entries')->where('action', 'like', 'email.%')->count())->toBeGreaterThanOrEqual(5)
            ->and($alice->refresh()->email)->toBe($changed)
            ->and($bob->refresh()->pending_email)->toBe($byOperator);

        foreach ([$own, $byOperator, $changed, $provider] as $address) {
            expect(tablesContaining($address))->toBe([], "{$address} in plain text");
            expect(str_contains((string) @file_get_contents($logs.'/single.log'), $address))->toBeFalse("{$address} in the log");
        }
        expect((string) file_get_contents($logs.'/single.log'))->toContain('[email]');
    } finally {
        File::deleteDirectory($logs);
    }
});
