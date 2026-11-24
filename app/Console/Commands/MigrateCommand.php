<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Database\Backup\BackupFailed;
use App\Database\Backup\DatabaseDumper;
use App\Database\Backup\PreMigrateBackups;
use App\Database\MigrationLock;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * The migration step of an upgrade, run by the one-off compose service
 * `migrate` before web, worker, scheduler and reverb start.
 *
 *   1. Nothing pending: exit 0, no backup.
 *   2. Otherwise pg_dump (custom format) to TESSERARR_BACKUP_DIR first; a failed
 *      or empty backup stops the run with exit 1 and nothing is migrated.
 *   3. `migrate --force`, then keep the newest backups (tesserarr.backups.keep,
 *      TESSERARR_BACKUP_KEEP, default 5).
 *
 * The whole sequence holds the MigrationLock (a PostgreSQL advisory lock), so
 * two migrate containers never dump and migrate at the same time. Migrations
 * only go forward; going back means restoring the backup with the previous
 * image.
 *
 * Logged with origin `cli`: start, the backup's file name and size, the result.
 * Never data from the database.
 */
final class MigrateCommand extends Command
{
    protected $signature = 'tesserarr:migrate
        {--lock-timeout=300 : seconds to wait for another tesserarr:migrate}';

    protected $description = 'Back up the database, then run the pending migrations';

    public function handle(Migrator $migrator, DatabaseDumper $dumper, MigrationLock $lock): int
    {
        $this->log('migrate.start');

        try {
            $keep = PreMigrateBackups::keep(config('tesserarr.backups.keep'));
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage().'; nothing was migrated.');
            $this->log('migrate.invalid_config', ['setting' => 'backups.keep'], 'error');

            return SymfonyCommand::FAILURE;
        }

        if (! $lock->acquire((int) $this->option('lock-timeout'))) {
            $this->error('Another tesserarr:migrate holds the migration lock; nothing was migrated.');
            $this->log('migrate.locked', [], 'error');

            return SymfonyCommand::FAILURE;
        }

        try {
            return $this->migrate($migrator, $dumper, $keep);
        } finally {
            // A failed release must not replace the result or the original
            // failure; the session lock falls when the connection ends anyway.
            try {
                $lock->release();
            } catch (Throwable $unlock) {
                $this->log('migrate.unlock_failed', ['error' => $unlock::class], 'warning');
            }
        }
    }

    private function migrate(Migrator $migrator, DatabaseDumper $dumper, int $keep): int
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

        // A failing migration is reported by its class only: the message of a
        // QueryException carries SQL and bindings, so it reaches neither the log
        // nor the console, and it is not rethrown to the console kernel either.
        try {
            $code = $this->call('migrate', ['--force' => true]);
        } catch (Throwable $failure) {
            $code = null;
            $error = $failure::class;
        }
        if ($code !== SymfonyCommand::SUCCESS) {
            $error ??= 'exit code '.var_export($code, true);
            $this->error(sprintf('Migration failed (%s); the backup %s is kept.', $error, $backup->name()));
            $this->log('migrate.failed', ['pending' => $pending, 'error' => $error, 'backup' => $backup->name()], 'error');

            return SymfonyCommand::FAILURE;
        }

        $removed = $backups->prune($keep);
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
     * @param  array<string, mixed>  $context
     */
    private function log(string $event, array $context = [], string $level = 'info'): void
    {
        Log::log($level, $event, ['origin' => 'cli', 'command' => 'tesserarr:migrate'] + $context);
    }
}
