<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Privacy\RedactEmailsProcessor;
use App\Privacy\RedactingTap;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Level;
use Monolog\LogRecord;

/*
| No address reaches a log (task 8a): RedactEmailsProcessor replaces every
| address in message, context and extra with "[email]", and an exception in
| the context by a redacted description of it. RedactingTap puts it on every
| channel of config/logging.php.
*/

function logRecord(string $message, array $context = [], array $extra = []): LogRecord
{
    return new LogRecord(new DateTimeImmutable, 'test', Level::Error, $message, $context, $extra);
}

/** The log files under a directory of this test that contain $needle. */
function logFilesContaining(string $dir, string $needle): array
{
    return array_values(array_filter(
        File::allFiles($dir),
        fn (SplFileInfo $file) => str_contains((string) file_get_contents($file->getPathname()), $needle),
    ));
}

/** Points every channel that writes a file into $dir, and returns $dir. */
function logsInto(string $dir): string
{
    File::ensureDirectoryExists($dir);
    foreach ((array) config('logging.channels') as $name => $channel) {
        if (isset($channel['path'])) {
            config(["logging.channels.{$name}.path" => $dir.'/'.$name.'.log']);
        }
    }
    config(['logging.channels.stack.channels' => ['single', 'daily']]);
    app()->forgetInstance('log');
    Log::clearResolvedInstances();

    return $dir;
}

it('redacts email addresses in message, context and extra', function () {
    $record = (new RedactEmailsProcessor)(logRecord(
        'mail to Alice@Example.org failed',
        ['to' => ['bob@example.net', 'name' => 'Bob'], 'count' => 2],
        ['user' => "j\u{00E9}r\u{00F4}me@b\u{00FC}cher.test"],
    ));

    expect($record->message)->toBe('mail to [email] failed')
        ->and($record->context)->toBe(['to' => ['[email]', 'name' => 'Bob'], 'count' => 2])
        ->and($record->extra)->toBe(['user' => '[email]']);
});

it('redacts addresses with every atext character, URL-encoded ones, and addresses inside objects', function () {
    $carrier = new class
    {
        public string $to = 'hidden@example.org';
    };
    $json = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            return ['to' => 'json@example.org', 'n' => 1];
        }
    };

    $record = (new RedactEmailsProcessor)(logRecord(
        "o'brien@example.org, a+b/c=d#e@example.org, ?to=me%40example.org",
        ['object' => $carrier, 'json' => $json, 'date' => new DateTimeImmutable('2026-01-02T03:04:05+00:00'), 'level' => Level::Error],
    ));

    // "?" and "=" are atext: the query key goes with the address.
    expect($record->message)->toBe('[email], [email], [email]')
        ->and($record->context['object'])->toStartWith('[object class@anonymous')
        ->and($record->context['json'])->toBe(['to' => '[email]', 'n' => 1])
        ->and($record->context['date'])->toBe('2026-01-02T03:04:05+00:00')
        ->and($record->context['level'])->toBe(Level::Error->value)
        ->and(json_encode($record->context))->not->toContain('hidden@')->not->toContain('json@');
});

it('keeps text without an address unchanged', function () {
    $record = (new RedactEmailsProcessor)(logRecord('the @ sign alone, user@host, a@b', ['path' => '/srv/@me']));

    expect($record->message)->toBe('the @ sign alone, user@host, a@b')
        ->and($record->context)->toBe(['path' => '/srv/@me']);
});

it('replaces an exception in the context by a redacted description', function () {
    $address = 'probe-'.Str::lower(Str::random(6)).'@example.invalid';
    $previous = new LogicException("unknown {$address}");
    $exception = new RuntimeException("550 <{$address}> rejected", 3, $previous);

    $record = (new RedactEmailsProcessor)(logRecord('send failed', ['exception' => $exception]));
    $described = $record->context['exception'];

    expect($described)->toBeArray()
        ->and($described['class'])->toBe(RuntimeException::class)
        ->and($described['message'])->toBe('550 <[email]> rejected')
        ->and($described['code'])->toBe(3)
        ->and($described['file'])->toBe(__FILE__)
        ->and($described['line'])->toBeInt()
        ->and($described['previous']['class'])->toBe(LogicException::class)
        ->and($described['previous']['message'])->toBe('unknown [email]')
        ->and($described['trace'])->each->toMatch('/\A.+:\d+\z|\A\[internal]\z/')
        ->and(json_encode($described))->not->toContain($address);
});

it('puts the processor on every log channel through RedactingTap', function () {
    $channels = (array) config('logging.channels');

    expect($channels)->not->toBeEmpty();
    foreach ($channels as $name => $channel) {
        expect(in_array(RedactingTap::class, $channel['tap'] ?? [], true))->toBeTrue("channel {$name} has no RedactingTap");
    }
});

it('redacts email addresses in message, context and extra of every log channel', function () {
    $dir = logsInto(storage_path('framework/testing/logs-'.Str::random(8)));
    $address = 'probe-'.Str::lower(Str::random(6)).'@example.invalid';

    try {
        foreach (['stack', 'single', 'daily', 'monthly', 'emergency'] as $channel) {
            Log::channel($channel)->error("to {$address}", ['to' => $address]);
        }
        Log::error("default channel {$address}", ['nested' => ['to' => $address]]);

        expect(logFilesContaining($dir, '[email]'))->not->toBeEmpty()
            ->and(logFilesContaining($dir, $address))->toBeEmpty();
    } finally {
        File::deleteDirectory($dir);
    }
});

it('redacts an address in the message, a previous exception and a trace argument of a logged exception', function () {
    $dir = logsInto(storage_path('framework/testing/logs-'.Str::random(8)));
    $addr = 'probe-'.Str::lower(Str::random(6)).'@example.invalid';

    // The address is also an argument of a frame of the trace.
    $thrower = function (string $to): never {
        throw new RuntimeException("550 <{$to}> rejected", previous: new LogicException($to));
    };

    try {
        try {
            $thrower($addr);
        } catch (RuntimeException $e) {
            Log::error('send failed', ['exception' => $e]);
            report($e);
        }

        expect(logFilesContaining($dir, 'send failed'))->not->toBeEmpty()
            ->and(logFilesContaining($dir, $addr))->toBeEmpty();
    } finally {
        File::deleteDirectory($dir);
    }
});
