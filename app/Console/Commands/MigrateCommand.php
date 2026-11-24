<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Database\Backup\BackupFailed;
use App\Database\Backup\DatabaseDumper;
use App\Database\Backup\PreMigrateBackups;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * The migration step of an upgrade, run by the one-off compose service
 * `migrate` before web, worker, scheduler and reverb start.
 *
 *   1. Nothing pending: exit 0, no backup.
 *   2. Otherwise pg_dump (custom format) to TESSERARR_BACKUP_DIR first; a failed
 *      or empty backup stops the run with exit 1 and nothing is migrated.
 *   3. `migrate --force`, then keep the five newest backups.
 *
 * The whole sequence holds a PostgreSQL advisory lock, so two migrate
 * containers never dump and migrate at the same time. `migrate --isolated`
 * cannot do that on a fresh installation: its lock lives in the cache table
 * the first migration creates. Migrations only go forward; going back means
 * restoring the backup with the previous image.
 *
 * Logged with origin `cli`: start, the backup's file name and size, the result.
 * Never data from the database.
 */
final class MigrateCommand extends Command
{
    /** pg_advisory_lock key: "tesserarr:migrate" as a fixed 64-bit number. */
    public const LOCK_KEY = 7_514_731_002_316_401_517;

    protected $signature = 'tesserarr:migrate
        {--lock-timeout=300 : seconds to wait for another tesserarr:migrate}';

    protected $description = 'Back up the database, then run the pending migrations';

    public function handle(Migrator $migrator, DatabaseDumper $dumper): int
    {
        $connection = $migrator->resolveConnection(null);
        $this->log('migrate.start');

        if (! $this->acquireLock($connection)) {
            $this->error('Another tesserarr:migrate holds the migration lock; nothing was migrated.');
            $this->log('migrate.locked', [], 'error');

            return SymfonyCommand::FAILURE;
        }

        try {
            return $this->migrate($migrator, $dumper);
        } finally {
            $connection->select('select pg_advisory_unlock(?)', [self::LOCK_KEY]);
        }
    }

    private function migrate(Migrator $migrator, DatabaseDumper $dumper): int
    {
        $pending = $this->pending($migrator);
        if ($pending === 0) {
            $this->info('Nothing to migrate; no backup written.');
            $this->log('migrate.nothing_pending');

            return SymfonyCommand::SUCCESS;
        }

        $backups = new PreMigrateBackups((string) config('tesserarr.backup.dir'));
        try {
            $backup = $backups->write($dumper, (string) config('app.version'));
        } catch (BackupFailed $failed) {
            $this->error('Backup failed, nothing was migrated: '.$failed->getMessage());
            $this->log('migrate.backup_failed', ['reason' => $failed->getMessage()], 'error');

            return SymfonyCommand::FAILURE;
        }
        $this->info(sprintf('Backup written: %s (%d bytes)', $backup->name(), $backup->bytes));
        $this->log('migrate.backup_written', ['file' => $backup->name(), 'bytes' => $backup->bytes]);

        try {
            $code = $this->call('migrate', ['--force' => true]);
        } catch (Throwable $failure) {
            // The class only: a QueryException message carries SQL and bindings.
            $this->log('migrate.failed', ['pending' => $pending, 'error' => $failure::class, 'backup' => $backup->name()], 'error');

            throw $failure;
        }
        if ($code !== SymfonyCommand::SUCCESS) {
            $this->log('migrate.failed', ['pending' => $pending, 'exit_code' => $code, 'backup' => $backup->name()], 'error');

            return SymfonyCommand::FAILURE;
        }

        $removed = $backups->prune();
        $this->log('migrate.done', ['migrated' => $pending, 'backup' => $backup->name(), 'pruned' => $removed]);

        return SymfonyCommand::SUCCESS;
    }

    private function pending(Migrator $migrator): int
    {
        $paths = array_merge($migrator->paths(), [$this->laravel->databasePath('migrations')]);
        $files = $migrator->getMigrationFiles($paths);
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return count(array_diff(array_keys($files), $ran));
    }

    /**
     * Polls pg_try_advisory_lock instead of waiting in pg_advisory_lock with a
     * lock_timeout: a timeout error would abort an enclosing transaction.
     */
    private function acquireLock(ConnectionInterface $connection): bool
    {
        $deadline = microtime(true) + max(1, (int) $this->option('lock-timeout'));
        do {
            $row = $connection->selectOne('select pg_try_advisory_lock(?) as locked', [self::LOCK_KEY]);
            if ((bool) ($row->locked ?? false)) {
                return true;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $event, array $context = [], string $level = 'info'): void
    {
        Log::log($level, $event, ['origin' => 'cli', 'command' => 'tesserarr:migrate'] + $context);
    }
}
