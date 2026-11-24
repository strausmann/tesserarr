<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Database\Backup\BackupFailed;
use App\Database\Backup\DatabaseDumper;
use App\Database\MigrationLock;
use App\Database\PostgresMigrationLock;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// tesserarr:migrate (CONTRIBUTING.md, "Set up your environment"): a backup
// before every migration that changes something, never a migration without it.
// The dumper is replaced here; the real pg_dump of the image is exercised by
// scripts/smoke/migrate.sh in the Docker job.

/**
 * A dumper that runs a callback instead of pg_dump.
 */
final class FakeDumper implements DatabaseDumper
{
    public int $calls = 0;

    /** @param  Closure(string): void  $write */
    public function __construct(private Closure $write) {}

    public function dump(string $path): void
    {
        $this->calls++;
        ($this->write)($path);
    }
}

beforeEach(function () {
    $this->backupDir = sys_get_temp_dir().'/tesserarr-backups-'.bin2hex(random_bytes(6));
    $this->migrationDir = sys_get_temp_dir().'/tesserarr-migrations-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->migrationDir);
    config(['tesserarr.backup.dir' => $this->backupDir, 'app.version' => '0.1.0-rc.1']);

    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) {
        $this->logged[] = $event->message.' '.json_encode($event->context);
    });
});

afterEach(function () {
    File::deleteDirectory($this->backupDir);
    File::deleteDirectory($this->migrationDir);
});

function useDumper(Closure $write): FakeDumper
{
    $dumper = new FakeDumper($write);
    app()->instance(DatabaseDumper::class, $dumper);

    return $dumper;
}

/**
 * Registers a pending migration that creates the table t4_probe.
 */
function pendingProbeMigration(string $dir): void
{
    file_put_contents($dir.'/2099_01_01_000000_create_t4_probe_table.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('t4_probe', function (Blueprint $table) {
                    $table->id();
                });
            }
        };
        PHP);
    app('migrator')->path($dir);
}

/** @return list<string> */
function dumpNames(string $dir): array
{
    $names = array_map('basename', glob($dir.'/pre-migrate-*.dump') ?: []);
    sort($names);

    return $names;
}

it('skips the backup when nothing is pending', function () {
    $dumper = useDumper(fn (string $path) => file_put_contents($path, 'dump'));

    $this->artisan('tesserarr:migrate')
        ->expectsOutputToContain('Nothing to migrate')
        ->assertExitCode(0);

    expect($dumper->calls)->toBe(0)
        ->and(is_dir($this->backupDir) ? dumpNames($this->backupDir) : [])->toBe([]);
});

it('writes a non-empty 0600 dump before migrating', function () {
    pendingProbeMigration($this->migrationDir);
    $tableAtDumpTime = null;
    useDumper(function (string $path) use (&$tableAtDumpTime) {
        $tableAtDumpTime = Schema::hasTable('t4_probe');
        file_put_contents($path, 'PGDMP custom format');
    });

    $this->artisan('tesserarr:migrate')->assertExitCode(0);

    $names = dumpNames($this->backupDir);
    expect($tableAtDumpTime)->toBeFalse()
        ->and(Schema::hasTable('t4_probe'))->toBeTrue()
        ->and($names)->toHaveCount(1)
        ->and($names[0])->toMatch('/^pre-migrate-\d{8}T\d{6}Z-0\.1\.0-rc\.1\.dump$/');

    $file = $this->backupDir.'/'.$names[0];
    clearstatcache();
    expect(filesize($file))->toBeGreaterThan(0)
        ->and(fileperms($file) & 0777)->toBe(0600);
});

it('does not migrate when the dump fails', function () {
    pendingProbeMigration($this->migrationDir);
    useDumper(fn (string $path) => throw new BackupFailed('pg_dump exited with code 1'));

    $this->artisan('tesserarr:migrate')
        ->expectsOutputToContain('Backup failed, nothing was migrated')
        ->assertExitCode(1);

    expect(Schema::hasTable('t4_probe'))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', '2099_01_01_000000_create_t4_probe_table')->exists())->toBeFalse();
});

it('does not migrate when the dump is empty', function () {
    pendingProbeMigration($this->migrationDir);
    useDumper(fn (string $path) => touch($path));

    $this->artisan('tesserarr:migrate')->assertExitCode(1);

    expect(Schema::hasTable('t4_probe'))->toBeFalse()
        ->and(dumpNames($this->backupDir))->toBe([]);
});

it('keeps the five newest dumps', function () {
    File::ensureDirectoryExists($this->backupDir);
    $old = [];
    foreach (range(1, 6) as $day) {
        $name = sprintf('pre-migrate-202601%02dT000000Z-0.0.%d.dump', $day, $day);
        file_put_contents($this->backupDir.'/'.$name, 'old');
        $old[] = $name;
    }
    file_put_contents($this->backupDir.'/notes.txt', 'not a backup');
    pendingProbeMigration($this->migrationDir);
    useDumper(fn (string $path) => file_put_contents($path, 'new'));

    $this->artisan('tesserarr:migrate')->assertExitCode(0);

    $names = dumpNames($this->backupDir);
    expect($names)->toHaveCount(5)
        ->and($names)->not->toContain($old[0])
        ->and($names)->not->toContain($old[1])
        ->and(array_slice($names, 0, 4))->toBe(array_slice($old, 2))
        ->and(file_exists($this->backupDir.'/notes.txt'))->toBeTrue();
});

it('never logs table contents', function () {
    $marker = 'T4-ROW-'.bin2hex(random_bytes(4));
    User::factory()->create(['name' => $marker]);
    pendingProbeMigration($this->migrationDir);
    useDumper(fn (string $path) => file_put_contents($path, "COPY users FROM stdin;\n{$marker}\n"));

    $this->artisan('tesserarr:migrate')
        ->doesntExpectOutputToContain($marker)
        ->assertExitCode(0);

    $log = implode("\n", $this->logged);
    $name = dumpNames($this->backupDir)[0];
    expect($log)->not->toContain($marker)
        ->and($log)->toContain($name)
        ->and($log)->toContain('"origin":"cli"')
        ->and($log)->toContain('"bytes":'.filesize($this->backupDir.'/'.$name));
});

it('does not dump or migrate while another run holds the lock', function () {
    pendingProbeMigration($this->migrationDir);
    $dumper = useDumper(fn (string $path) => file_put_contents($path, 'dump'));
    $other = DB::connection('pgsql')->getPdo();
    $holder = new PDO($other->getAttribute(PDO::ATTR_DRIVER_NAME).':host='.config('database.connections.pgsql.host')
        .';port='.config('database.connections.pgsql.port').';dbname='.config('database.connections.pgsql.database'),
        (string) config('database.connections.pgsql.username'), (string) config('database.connections.pgsql.password'));
    $holder->query('select pg_advisory_lock('.PostgresMigrationLock::KEY.')');

    try {
        $this->artisan('tesserarr:migrate', ['--lock-timeout' => 1])
            ->expectsOutputToContain('Another tesserarr:migrate holds the migration lock')
            ->assertExitCode(1);
    } finally {
        $holder->query('select pg_advisory_unlock('.PostgresMigrationLock::KEY.')');
    }

    expect($dumper->calls)->toBe(0)
        ->and(Schema::hasTable('t4_probe'))->toBeFalse();
});

it('fails with exit 1, keeps the backup and logs no sql when a migration fails', function () {
    file_put_contents($this->migrationDir.'/2099_01_01_000001_broken.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Support\Facades\DB;

        return new class extends Migration
        {
            public function up(): void
            {
                DB::statement("select * from t4_missing_table where note = 'T4-SQL-MARKER'");
            }
        };
        PHP);
    app('migrator')->path($this->migrationDir);
    useDumper(fn (string $path) => file_put_contents($path, 'dump'));

    $this->artisan('tesserarr:migrate')
        ->expectsOutputToContain('Migration failed')
        ->doesntExpectOutputToContain('T4-SQL-MARKER')
        ->assertExitCode(1);

    $log = implode("\n", $this->logged);
    expect(dumpNames($this->backupDir))->toHaveCount(1)
        ->and($log)->toContain('migrate.failed')
        ->and($log)->toContain('"error":"Illuminate\\\\Database\\\\QueryException"')
        ->and($log)->not->toContain('T4-SQL-MARKER')
        ->and($log)->not->toContain('t4_missing_table');
});

it('removes a partial backup and logs the failure when the dumper aborts', function () {
    pendingProbeMigration($this->migrationDir);
    useDumper(function (string $path) {
        file_put_contents($path, 'half a dump');
        throw new RuntimeException('killed');
    });

    $this->artisan('tesserarr:migrate')
        ->expectsOutputToContain('Backup failed, nothing was migrated')
        ->assertExitCode(1);

    expect(Schema::hasTable('t4_probe'))->toBeFalse()
        ->and(array_values(array_diff(scandir($this->backupDir) ?: [], ['.', '..'])))->toBe([])
        ->and(implode("\n", $this->logged))->toContain('migrate.backup_failed');
});

it('keeps the older good backups after aborted runs', function () {
    File::ensureDirectoryExists($this->backupDir);
    $good = [];
    foreach (range(1, 5) as $day) {
        $name = sprintf('pre-migrate-202601%02dT000000Z-0.0.%d.dump', $day, $day);
        file_put_contents($this->backupDir.'/'.$name, 'good');
        $good[] = $name;
    }
    pendingProbeMigration($this->migrationDir);

    foreach (range(1, 3) as $attempt) {
        $this->travel(1)->seconds();
        useDumper(function (string $path) {
            file_put_contents($path, 'half a dump');
            throw new RuntimeException('timed out');
        });
        $this->artisan('tesserarr:migrate')->assertExitCode(1);
    }
    $this->travel(1)->seconds();
    useDumper(fn (string $path) => file_put_contents($path, 'complete'));
    $this->artisan('tesserarr:migrate')->assertExitCode(0);

    $names = dumpNames($this->backupDir);
    expect($names)->toHaveCount(5)
        ->and(array_slice($names, 0, 4))->toBe(array_slice($good, 1))
        ->and(file_get_contents($this->backupDir.'/'.$names[4]))->toBe('complete')
        ->and(glob($this->backupDir.'/*.partial') ?: [])->toBe([]);
});

it('reports the original failure when releasing the lock fails', function () {
    pendingProbeMigration($this->migrationDir);
    useDumper(fn (string $path) => throw new BackupFailed('pg_dump exited with code 1'));
    app()->instance(MigrationLock::class, new class implements MigrationLock
    {
        public function acquire(int $timeoutSeconds): bool
        {
            return true;
        }

        public function release(): void
        {
            throw new RuntimeException('connection lost');
        }
    });

    $this->artisan('tesserarr:migrate')
        ->expectsOutputToContain('Backup failed, nothing was migrated')
        ->assertExitCode(1);

    $log = implode("\n", $this->logged);
    expect($log)->toContain('migrate.backup_failed')
        ->and($log)->toContain('migrate.unlock_failed')
        ->and($log)->toContain('"error":"RuntimeException"');
});

it('keeps five backups unless configured otherwise', function () {
    expect(config('tesserarr.backups.keep'))->toBe(5);
});

it('keeps the configured number of backups, from 1 to 50', function (int $keep) {
    config(['tesserarr.backups.keep' => $keep]);
    File::ensureDirectoryExists($this->backupDir);
    foreach (range(1, 9) as $day) {
        file_put_contents($this->backupDir.sprintf('/pre-migrate-202601%02dT000000Z-0.0.%d.dump', $day, $day), 'old');
    }
    pendingProbeMigration($this->migrationDir);
    useDumper(fn (string $path) => file_put_contents($path, 'new'));

    $this->artisan('tesserarr:migrate')->assertExitCode(0);

    expect(dumpNames($this->backupDir))->toHaveCount(min($keep, 10));
})->with([1, 2, 50]);

it('refuses an invalid number of backups to keep before doing anything', function (mixed $keep) {
    config(['tesserarr.backups.keep' => $keep]);
    pendingProbeMigration($this->migrationDir);
    $dumper = useDumper(fn (string $path) => file_put_contents($path, 'dump'));

    $this->artisan('tesserarr:migrate')
        ->expectsOutputToContain('TESSERARR_BACKUP_KEEP must be a whole number from 1 to 50')
        ->assertExitCode(1);

    expect($dumper->calls)->toBe(0)
        ->and(Schema::hasTable('t4_probe'))->toBeFalse();
})->with([0, 51, -1, 'abc', '2.5', '', null]);
