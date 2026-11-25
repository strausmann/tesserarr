<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Database\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The directory of the backups written before a migration.
 *
 * File names are `pre-migrate-<UTC timestamp with microseconds>-<8 random hex>-
 * <app version>.dump`, so sorting by name sorts by age and two runs in the same
 * moment still get two names. Every file is created with the mode 0600; only
 * files matching that pattern (or its `.partial`) are ever deleted, and only
 * through deleteOwnFile().
 */
final class PreMigrateBackups
{
    public const KEEP = 5;

    public const KEEP_MIN = 1;

    public const KEEP_MAX = 50;

    private const PATTERN = '/^pre-migrate-\d{8}T\d{6}\.\d{6}Z-[0-9a-f]{8}-[A-Za-z0-9._-]+\.dump$/';

    /** The unfinished file of a backup: the backup name plus `.partial`. */
    private const PARTIAL_PATTERN = '/^pre-migrate-\d{8}T\d{6}\.\d{6}Z-[0-9a-f]{8}-[A-Za-z0-9._-]+\.dump\.partial$/';

    private readonly string $directory;

    /**
     * The directory is normalised once (trailing slashes removed, `/` stays
     * `/`, resolved with realpath() when it exists), so deleteOwnFile() can
     * compare it with dirname() of a file inside it.
     */
    public function __construct(string $directory)
    {
        $trimmed = rtrim($directory, '/');
        $normalised = $trimmed === '' && $directory !== '' ? '/' : $trimmed;
        $resolved = $normalised !== '' ? realpath($normalised) : false;

        $this->directory = $resolved !== false ? $resolved : $normalised;
    }

    /**
     * pg_dump writes to `<name>.partial`, outside the rotation pattern; only a
     * complete, non-empty dump gets its final name, through link() plus unlink():
     * link() is atomic and fails when the name exists, so a finished backup is
     * never overwritten. Where the file system has no hard links, the existence
     * check plus rename() runs under the migration lock instead. Whatever goes
     * wrong, the partial file is removed, so an aborted run never counts as a
     * backup.
     *
     * @throws BackupFailed when the dump fails, is aborted or leaves an empty file
     */
    public function write(DatabaseDumper $dumper, string $version): BackupFile
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new BackupFailed('the backup directory cannot be created');
        }

        $this->removeStalePartials();

        $path = $this->directory.'/'.$this->name($version);
        $partial = $path.'.partial';
        $previous = umask(0077);
        try {
            if (@touch($partial) === false) {
                throw new BackupFailed('the backup directory is not writable');
            }
            @chmod($partial, 0600);
            $dumper->dump($partial);

            clearstatcache(true, $partial);
            $bytes = is_file($partial) ? (int) filesize($partial) : 0;
            if ($bytes === 0) {
                throw new BackupFailed('the backup is empty');
            }
            @chmod($partial, 0600);
            if (file_exists($path) || is_link($path)) {
                throw new BackupFailed('a backup with this name exists already');
            }
            if (@link($partial, $path)) {
                $this->deleteOwnFile(basename($partial));
            } elseif (! @rename($partial, $path)) {
                // No hard links on this file system: rename() after the check
                // above, under the migration lock, so no other run creates the name.
                throw new BackupFailed('the backup could not get its final name');
            }
        } catch (Throwable $failure) {
            $this->deleteOwnFile(basename($partial));
            throw $failure instanceof BackupFailed
                ? $failure
                : new BackupFailed('the dump was aborted ('.$failure::class.')', previous: $failure);
        } finally {
            umask($previous);
        }

        return new BackupFile($path, $bytes);
    }

    /**
     * Removes the `.partial` files a hard-killed earlier run left behind
     * (SIGKILL, OOM, host restart): they never count as backups, but would fill
     * the disk. Safe because the caller holds the MigrationLock, so no other run
     * is writing one. Only regular files matching PARTIAL_PATTERN go; symlinks
     * and every other file stay. Logged with names and count, never contents.
     *
     * @return list<string> the names of the removed files
     */
    public function removeStalePartials(): array
    {
        $removed = [];
        foreach (scandir($this->directory) ?: [] as $name) {
            if (preg_match(self::PARTIAL_PATTERN, $name) === 1 && $this->deleteOwnFile($name)) {
                $removed[] = $name;
            }
        }
        if ($removed !== []) {
            Log::warning('migrate.partials_removed', [
                'origin' => 'cli',
                'command' => 'tesserarr:migrate',
                'count' => count($removed),
                'files' => $removed,
            ]);
        }

        return $removed;
    }

    /**
     * The configured number of backups to keep: a whole number from KEEP_MIN to
     * KEEP_MAX, given as an integer or a string of digits (from the environment).
     *
     * @throws InvalidArgumentException for anything else
     */
    public static function keep(mixed $value): int
    {
        $number = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^[0-9]{1,3}$/', $value) === 1 => (int) $value,
            default => null,
        };
        if ($number === null || $number < self::KEEP_MIN || $number > self::KEEP_MAX) {
            throw new InvalidArgumentException(sprintf(
                'TESSERARR_BACKUP_KEEP must be a whole number from %d to %d',
                self::KEEP_MIN,
                self::KEEP_MAX,
            ));
        }

        return $number;
    }

    /**
     * Removes all but the newest $keep backups.
     *
     * @return list<string> the names of the removed files
     */
    public function prune(int $keep = self::KEEP): array
    {
        $names = array_values(array_filter(
            scandir($this->directory) ?: [],
            fn (string $name): bool => preg_match(self::PATTERN, $name) === 1,
        ));
        sort($names);

        $removed = [];
        foreach (array_slice($names, 0, max(0, count($names) - $keep)) as $name) {
            if ($this->deleteOwnFile($name)) {
                $removed[] = $name;
            }
        }

        return $removed;
    }

    /**
     * The one place that deletes a file. It deletes only a regular file directly
     * in the backup directory whose name is an own backup or its `.partial`: no
     * directory separator, no symlink, nothing else.
     */
    public function deleteOwnFile(string $name): bool
    {
        if ($name === '' || strpbrk($name, "/\\\0") !== false || $name !== basename($name)) {
            return false;
        }
        if (preg_match(self::PATTERN, $name) !== 1 && preg_match(self::PARTIAL_PATTERN, $name) !== 1) {
            return false;
        }
        $path = $this->directory.'/'.$name;
        if (is_link($path) || ! is_file($path) || dirname($path) !== $this->directory) {
            return false;
        }

        // The name is checked above against the own fixed patterns, without any
        // separator, so it cannot point outside the backup directory.
        return @unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use
    }

    private function name(string $version): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $version);

        return sprintf(
            'pre-migrate-%s-%s-%s.dump',
            Carbon::now('UTC')->format('Ymd\THis.u\Z'),
            bin2hex(random_bytes(4)),
            $safe === '' ? 'unknown' : $safe,
        );
    }
}
