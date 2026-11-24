<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Database\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The directory of the backups written before a migration.
 *
 * File names are `pre-migrate-<UTC timestamp>-<app version>.dump`, so sorting
 * by name sorts by age. Every file is created with the mode 0600; only files
 * matching that pattern are ever pruned.
 */
final class PreMigrateBackups
{
    public const KEEP = 5;

    public const KEEP_MIN = 1;

    public const KEEP_MAX = 50;

    private const PATTERN = '/^pre-migrate-\d{8}T\d{6}Z-[A-Za-z0-9._-]+\.dump$/';

    /** The unfinished file of a backup: the backup name plus `.partial`. */
    private const PARTIAL_PATTERN = '/^pre-migrate-\d{8}T\d{6}Z-[A-Za-z0-9._-]+\.dump\.partial$/';

    public function __construct(private readonly string $directory) {}

    /**
     * pg_dump writes to `<name>.partial`, outside the rotation pattern; only a
     * complete, non-empty dump is renamed to its final name (atomic within the
     * directory). Whatever goes wrong, the partial file is removed, so an
     * aborted run never counts as a backup.
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
            if (! @rename($partial, $path)) {
                throw new BackupFailed('the backup could not be renamed to its final name');
            }
        } catch (Throwable $failure) {
            @unlink($partial);
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
            $path = $this->directory.'/'.$name;
            if (preg_match(self::PARTIAL_PATTERN, $name) !== 1 || is_link($path) || ! is_file($path)) {
                continue;
            }
            if (@unlink($path)) {
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
            if (@unlink($this->directory.'/'.$name)) {
                $removed[] = $name;
            }
        }

        return $removed;
    }

    private function name(string $version): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $version);

        return sprintf('pre-migrate-%s-%s.dump', Carbon::now('UTC')->format('Ymd\THis\Z'), $safe === '' ? 'unknown' : $safe);
    }
}
