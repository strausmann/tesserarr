<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Database\Backup;

use Illuminate\Support\Carbon;

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

    private const PATTERN = '/^pre-migrate-\d{8}T\d{6}Z-[A-Za-z0-9._-]+\.dump$/';

    public function __construct(private readonly string $directory) {}

    /**
     * @throws BackupFailed when the dump fails or leaves an empty file
     */
    public function write(DatabaseDumper $dumper, string $version): BackupFile
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new BackupFailed('the backup directory cannot be created');
        }

        $path = $this->directory.'/'.$this->name($version);
        $previous = umask(0077);
        try {
            if (@touch($path) === false) {
                throw new BackupFailed('the backup directory is not writable');
            }
            @chmod($path, 0600);
            $dumper->dump($path);
        } catch (BackupFailed $failed) {
            @unlink($path);
            throw $failed;
        } finally {
            umask($previous);
        }

        clearstatcache(true, $path);
        $bytes = is_file($path) ? (int) filesize($path) : 0;
        if ($bytes === 0) {
            @unlink($path);
            throw new BackupFailed('the backup is empty');
        }
        @chmod($path, 0600);

        return new BackupFile($path, $bytes);
    }

    /**
     * Removes all but the newest KEEP backups.
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
