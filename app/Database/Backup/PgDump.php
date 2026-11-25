<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Database\Backup;

use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * pg_dump in the custom format, the one pg_restore reads selectively.
 *
 * The password reaches pg_dump through the environment (PGPASSWORD), never as
 * an argument, so it does not appear in the process list. The TLS settings of
 * the Laravel connection (sslmode, sslrootcert, sslcert, sslkey) go along as
 * PGSSL* variables, so the backup is never weaker than the application's own
 * connection. A failure reports the exit code and the first line of pg_dump's
 * error output, shortened and with the password removed; never the environment.
 */
final class PgDump implements DatabaseDumper
{
    /** libpq environment variable per Laravel connection option. */
    private const TLS_OPTIONS = [
        'sslmode' => 'PGSSLMODE',
        'sslrootcert' => 'PGSSLROOTCERT',
        'sslcert' => 'PGSSLCERT',
        'sslkey' => 'PGSSLKEY',
    ];

    private const MAX_ERROR_LENGTH = 200;

    /**
     * @param  array<string, mixed>  $connection  the connection's configuration (Connection::getConfig())
     */
    public function __construct(
        private readonly array $connection,
        private readonly string $binary = 'pg_dump',
        private readonly int $timeoutSeconds = 3600,
    ) {}

    public function dump(string $path): void
    {
        $process = new Process($this->command($path), null, $this->environment(), null, $this->timeoutSeconds);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new BackupFailed(sprintf('pg_dump timed out after %d s', $this->timeoutSeconds));
        } catch (ProcessSignaledException $signaled) {
            throw new BackupFailed(sprintf('pg_dump was terminated by signal %d', $signaled->getSignal()));
        } catch (ProcessRuntimeException) {
            // The exception message names the command line; the class is enough.
            throw new BackupFailed('pg_dump could not be run');
        }

        if (! $process->isSuccessful()) {
            $detail = $this->errorLine($process->getErrorOutput());
            throw new BackupFailed(sprintf(
                'pg_dump exited with code %s%s',
                var_export($process->getExitCode(), true),
                $detail === '' ? '' : ': '.$detail,
            ));
        }
    }

    /**
     * @return list<string>
     */
    public function command(string $path): array
    {
        return [
            $this->binary,
            '--format=custom',
            '--no-password',
            '--file='.$path,
            '--host='.(string) ($this->connection['host'] ?? '127.0.0.1'),
            '--port='.(string) ($this->connection['port'] ?? 5432),
            '--username='.(string) ($this->connection['username'] ?? ''),
            '--dbname='.(string) ($this->connection['database'] ?? ''),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        $environment = ['PGPASSWORD' => (string) ($this->connection['password'] ?? '')];
        foreach (self::TLS_OPTIONS as $option => $variable) {
            $value = $this->connection[$option] ?? null;
            if (is_string($value) && $value !== '') {
                $environment[$variable] = $value;
            }
        }

        return $environment;
    }

    private function errorLine(string $stderr): string
    {
        $line = trim(strtok(trim($stderr), "\n") ?: '');
        $password = (string) ($this->connection['password'] ?? '');
        if ($password !== '') {
            $line = str_replace($password, '***', $line);
        }

        return mb_strimwidth($line, 0, self::MAX_ERROR_LENGTH, '…');
    }
}
