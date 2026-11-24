<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Database\Backup\BackupFailed;
use App\Database\Backup\PgDump;

// The real pg_dump run is the Docker smoke test (scripts/smoke/migrate.sh);
// here: what reaches the process, and how a failure is reported.

/** @param  array<string, mixed>  $extra */
function pgDump(string $binary = 'pg_dump', array $extra = []): PgDump
{
    return new PgDump([
        'host' => 'db.example.invalid',
        'port' => 5433,
        'database' => 'tesserarr',
        'username' => 'tesserarr',
        'password' => 'p4ss-w0rd-in-env-only',
    ] + $extra, $binary);
}

it('passes the password through the environment, never as an argument', function () {
    $command = pgDump()->command('/backups/x.dump');

    expect(implode(' ', $command))->not->toContain('p4ss-w0rd-in-env-only')
        ->and(pgDump()->environment())->toBe(['PGPASSWORD' => 'p4ss-w0rd-in-env-only'])
        ->and($command)->toContain('--format=custom', '--no-password', '--file=/backups/x.dump',
            '--host=db.example.invalid', '--port=5433', '--username=tesserarr', '--dbname=tesserarr');
});

it('passes the tls settings of the connection to pg_dump', function () {
    $environment = pgDump(extra: [
        'sslmode' => 'verify-full',
        'sslrootcert' => '/certs/ca.pem',
        'sslcert' => '/certs/client.pem',
        'sslkey' => '/certs/client.key',
    ])->environment();

    expect($environment)->toMatchArray([
        'PGSSLMODE' => 'verify-full',
        'PGSSLROOTCERT' => '/certs/ca.pem',
        'PGSSLCERT' => '/certs/client.pem',
        'PGSSLKEY' => '/certs/client.key',
    ]);
});

it('sets no PGSSL variable the connection does not configure', function () {
    $environment = pgDump(extra: ['sslmode' => '', 'sslrootcert' => null])->environment();

    expect(array_keys($environment))->toBe(['PGPASSWORD']);
});

/**
 * The message of the BackupFailed a fake pg_dump with this stderr causes.
 */
function failureMessage(string $stderr): string
{
    $script = sys_get_temp_dir().'/tesserarr-fake-pg-dump-'.bin2hex(random_bytes(4));
    file_put_contents($script, "#!/bin/sh\ncat >&2 <<'ERR'\n{$stderr}\nERR\nenv >&2\nexit 3\n");
    chmod($script, 0700);

    try {
        pgDump($script)->dump(sys_get_temp_dir().'/unused.dump');
    } catch (BackupFailed $failed) {
        return $failed->getMessage();
    } finally {
        unlink($script);
    }

    return '';
}

it('reports a failing pg_dump with its exit code and the first error line, without the password', function () {
    $message = failureMessage("pg_dump: error: auth failed for p4ss-w0rd-in-env-only\nsecond line");

    expect($message)->toBe('pg_dump exited with code 3: pg_dump: error: auth failed for ***');
});

it('shortens a long error line', function () {
    $message = failureMessage('pg_dump: error: '.str_repeat('x', 400));

    expect(mb_strlen($message))->toBeLessThanOrEqual(240)
        ->and($message)->toEndWith('…')
        ->and($message)->not->toContain('PGPASSWORD');
});
