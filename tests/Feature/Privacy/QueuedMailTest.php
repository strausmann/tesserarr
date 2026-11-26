<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Mail\ConfirmEmailMail;
use App\Mail\MailDeliveryFailed;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\Support\Mail\RejectingTransport;

/*
| Mail with personal data on the queue (task 8a): the job holds recipient
| and link encrypted (ShouldBeEncrypted), in `jobs` and in `failed_jobs`; a
| transport failure reaches failed_jobs.exception as MailDeliveryFailed,
| without the address. The tests with the invitation mail follow in task 10a
| (pre-flight K15).
*/

/** The classes declared under a directory of app/. */
function appClassesIn(string $dir): array
{
    if (! is_dir(app_path($dir))) {
        return [];
    }

    $classes = [];
    foreach ((new Finder)->files()->in(app_path($dir))->name('*.php') as $file) {
        $classes[] = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $dir.'/'.$file->getRelativePathname());
    }

    return $classes;
}

beforeEach(function () {
    config(['queue.default' => 'database']);
});

it('requires ShouldBeEncrypted on every mailable and notification', function () {
    $mailables = array_filter([...appClassesIn('Mail'), ...appClassesIn('Notifications')], fn (string $class) => class_exists($class)
        && (is_subclass_of($class, Mailable::class) || is_subclass_of($class, Notification::class)));

    expect($mailables)->toContain(ConfirmEmailMail::class);
    foreach ($mailables as $class) {
        expect(is_subclass_of($class, ShouldBeEncrypted::class))->toBeTrue("{$class} carries personal data and must implement ShouldBeEncrypted");
    }

    // Nothing else lives in app/Mail besides the mailables, their failure and its trait.
    $others = array_diff(appClassesIn('Mail'), $mailables, [MailDeliveryFailed::class, 'App\\Mail\\TranslatesDeliveryFailures']);
    expect(array_values($others))->toBe([]);
});

it('encrypts the queued confirmation mail in jobs and failed_jobs', function () {
    $address = 'probe-'.Str::lower(Str::random(6)).'@example.invalid';
    $link = 'https://tesserarr.example.org/email/confirm#'.Str::random(43);

    // The queued mail keeps the name of its mailer: a transport that refuses
    // the recipient, as an SMTP server would.
    config(['mail.default' => 'rejecting', 'mail.mailers.rejecting' => ['transport' => 'rejecting']]);
    Mail::extend('rejecting', fn () => new RejectingTransport);
    Mail::to($address)->queue(new ConfirmEmailMail($link, 60, 'alice'));

    $payload = (string) DB::table('jobs')->value('payload');
    $command = json_decode($payload, true)['data']['command'];
    expect($payload)->not->toContain($address)->not->toContain($link)
        ->and($command)->not->toStartWith('O:')
        ->and(Crypt::decryptString($command))->toContain('ConfirmEmailMail');

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);

    $failed = DB::table('failed_jobs')->first(['payload', 'exception']);
    expect($failed)->not->toBeNull()
        ->and((string) $failed->payload)->not->toContain($address)->not->toContain($link)
        ->and((string) $failed->exception)->toContain(MailDeliveryFailed::class)
        ->toContain('TransportException')
        ->not->toContain($address)
        ->not->toContain('Recipient address rejected');
});

it('turns a transport failure into MailDeliveryFailed without the recipient or the cause', function () {
    config(['mail.default' => 'rejecting', 'mail.mailers.rejecting' => ['transport' => 'rejecting']]);
    Mail::extend('rejecting', fn () => new RejectingTransport);

    expect(fn () => (new ConfirmEmailMail('https://example.org/x', 60, 'alice'))->to('alice@example.invalid')->send(app('mail.manager')))
        ->toThrow(function (MailDeliveryFailed $e) {
            expect($e->getMessage())->not->toContain('alice@example.invalid')
                ->and($e->getPrevious())->toBeNull()
                ->and($e->getCode())->toBe(550);
        });
});
