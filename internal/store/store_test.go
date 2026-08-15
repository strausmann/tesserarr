package store

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"testing/fstest"
)

// wantSchemaVersion is the number of migration files actually embedded under
// migrations/ right now (0001_initial.sql, 0002_sessions.sql,
// 0003_api_tokens.sql, 0004_forward_auth_profile.sql,
// 0005_api_token_expiry.sql, 0006_identity_email_verification.sql). It is a
// single named constant, not a literal repeated in every test below, so
// adding the next migration only means updating this one line rather than
// hunting down every hardcoded expectation across the file.
const wantSchemaVersion = 6

func TestOpenCreatesSchema(t *testing.T) {
	dbPath := filepath.Join(t.TempDir(), "test.db")

	st, err := Open(dbPath)
	if err != nil {
		t.Fatalf("Open() returned error: %v", err)
	}
	t.Cleanup(func() { _ = st.Close() })

	version, err := st.SchemaVersion()
	if err != nil {
		t.Fatalf("SchemaVersion() returned error: %v", err)
	}
	if version != wantSchemaVersion {
		t.Errorf("SchemaVersion() = %d, want %d", version, wantSchemaVersion)
	}
}

func TestOpenIsIdempotent(t *testing.T) {
	dbPath := filepath.Join(t.TempDir(), "test.db")

	first, err := Open(dbPath)
	if err != nil {
		t.Fatalf("first Open() returned error: %v", err)
	}
	if err := first.Close(); err != nil {
		t.Fatalf("Close() returned error: %v", err)
	}

	second, err := Open(dbPath)
	if err != nil {
		t.Fatalf("second Open() returned error: %v", err)
	}
	t.Cleanup(func() { _ = second.Close() })

	version, err := second.SchemaVersion()
	if err != nil {
		t.Fatalf("SchemaVersion() returned error: %v", err)
	}
	if version != wantSchemaVersion {
		t.Errorf("SchemaVersion() after reopen = %d, want %d", version, wantSchemaVersion)
	}
}

func TestOpenFailsOnUnwritablePath(t *testing.T) {
	// A directory that does not exist and cannot be created below a file.
	parent := filepath.Join(t.TempDir(), "not-a-directory")
	if err := writeFile(parent, "x"); err != nil {
		t.Fatalf("setup failed: %v", err)
	}
	dbPath := filepath.Join(parent, "nested", "test.db")

	if _, err := Open(dbPath); err == nil {
		t.Fatal("Open() returned no error, want error for unwritable path")
	}
}

func writeFile(path, content string) error {
	return os.WriteFile(path, []byte(content), 0o600)
}

// newTestStore opens a database at a fresh temp path without applying the
// real embedded migrations, so tests can hand migrate() an arbitrary,
// disposable fs.FS instead.
func newTestStore(t *testing.T) *Store {
	t.Helper()
	dbPath := filepath.Join(t.TempDir(), "test.db")
	db, err := openDB(dbPath)
	if err != nil {
		t.Fatalf("openDB() returned error: %v", err)
	}
	t.Cleanup(func() { _ = db.Close() })
	return &Store{db: db}
}

// TestMigrateRejectsMigrationOlderThanAlreadyApplied reproduces the two real
// failure modes of version-by-directory-position: a migration whose own
// number is lower than one already applied must neither silently vanish nor
// crash the next Open() with "table already exists" — it must fail loudly,
// naming the file and the conflicting versions, before touching the schema.
func TestMigrateRejectsMigrationOlderThanAlreadyApplied(t *testing.T) {
	st := newTestStore(t)

	// First rollout: only migration 0002 exists yet and gets applied.
	first := fstest.MapFS{
		"0002_second.sql": &fstest.MapFile{Data: []byte(`CREATE TABLE second_table (id TEXT);`)},
	}
	if err := st.migrate(first); err != nil {
		t.Fatalf("first migrate() returned error: %v", err)
	}

	// Second rollout: migration 0001 turns up late (typo fixed, branches
	// merged, file renamed — see task-2-brief.md). 0002 is unchanged.
	second := fstest.MapFS{
		"0001_first.sql":  &fstest.MapFile{Data: []byte(`CREATE TABLE first_table (id TEXT);`)},
		"0002_second.sql": &fstest.MapFile{Data: []byte(`CREATE TABLE second_table (id TEXT);`)},
	}
	err := st.migrate(second)
	if err == nil {
		t.Fatal("second migrate() returned no error, want a loud error for the out-of-order migration")
	}
	if !strings.Contains(err.Error(), "0001_first.sql") {
		t.Errorf("migrate() error = %q, want it to name the offending file 0001_first.sql", err.Error())
	}

	// No silent partial progress: schema_migrations must still only list the
	// migration that was genuinely applied in the first rollout.
	version, vErr := st.SchemaVersion()
	if vErr != nil {
		t.Fatalf("SchemaVersion() returned error: %v", vErr)
	}
	if version != 2 {
		t.Errorf("SchemaVersion() after rejected migrate() = %d, want 2 (unchanged)", version)
	}
}

// TestMigrateAppliesGapInNumberingCleanly ensures a missing version number
// (0001, then 0003 — no 0002) is not itself an error, and that the resulting
// schema version reflects the migrations' own numbers, not how many files
// were applied.
func TestMigrateAppliesGapInNumberingCleanly(t *testing.T) {
	st := newTestStore(t)

	migrations := fstest.MapFS{
		"0001_first.sql": &fstest.MapFile{Data: []byte(`CREATE TABLE first_table (id TEXT);`)},
		"0003_third.sql": &fstest.MapFile{Data: []byte(`CREATE TABLE third_table (id TEXT);`)},
	}
	if err := st.migrate(migrations); err != nil {
		t.Fatalf("migrate() returned error: %v", err)
	}

	version, err := st.SchemaVersion()
	if err != nil {
		t.Fatalf("SchemaVersion() returned error: %v", err)
	}
	if version != 3 {
		t.Errorf("SchemaVersion() = %d, want 3 (the highest migration's own number)", version)
	}
}

// TestMigrateRejectsDuplicateVersion ensures two files that parse to the same
// version number are a startup error, not a silent pick of whichever one the
// directory listing happens to return first.
func TestMigrateRejectsDuplicateVersion(t *testing.T) {
	st := newTestStore(t)

	migrations := fstest.MapFS{
		"0002_a.sql": &fstest.MapFile{Data: []byte(`CREATE TABLE table_a (id TEXT);`)},
		"0002_b.sql": &fstest.MapFile{Data: []byte(`CREATE TABLE table_b (id TEXT);`)},
	}
	if err := st.migrate(migrations); err == nil {
		t.Fatal("migrate() returned no error, want error for duplicate version 2")
	}
}

// TestMigrateRejectsFileWithoutNumericPrefix ensures a migration filename
// without a leading version number is a startup error rather than being
// silently assigned a position-based version.
func TestMigrateRejectsFileWithoutNumericPrefix(t *testing.T) {
	st := newTestStore(t)

	migrations := fstest.MapFS{
		"initial.sql": &fstest.MapFile{Data: []byte(`CREATE TABLE initial_table (id TEXT);`)},
	}
	if err := st.migrate(migrations); err == nil {
		t.Fatal("migrate() returned no error, want error for missing numeric version prefix")
	}
}

// TestMigrateRejectsDirectoryEntry ensures a nested path inside the
// migrations directory (which fstest.MapFS, like a real directory, surfaces
// as its own directory entry when listing ".") is a loud startup error, not
// silently ignored. A silently ignored migration is exactly the failure
// class this file's other tests exist to prevent: it exists, it never runs,
// and SchemaVersion() reports success regardless.
func TestMigrateRejectsDirectoryEntry(t *testing.T) {
	st := newTestStore(t)

	migrations := fstest.MapFS{
		"0001_first.sql":    &fstest.MapFile{Data: []byte(`CREATE TABLE first_table (id TEXT);`)},
		"subdir/0002_x.sql": &fstest.MapFile{Data: []byte(`CREATE TABLE second_table (id TEXT);`)},
	}
	err := st.migrate(migrations)
	if err == nil {
		t.Fatal("migrate() returned no error for a directory entry, want error")
	}
	if !strings.Contains(err.Error(), "subdir") {
		t.Errorf("migrate() error = %q, want it to name the offending directory subdir", err.Error())
	}
}

// TestMigrateWrapsApplyMigrationFailure ensures a migration that fails to
// apply is reported by name, not just as a generic migrate() failure — the
// operator needs to know which file to fix.
func TestMigrateWrapsApplyMigrationFailure(t *testing.T) {
	st := newTestStore(t)

	migrations := fstest.MapFS{
		"0001_broken.sql": &fstest.MapFile{Data: []byte("THIS IS NOT VALID SQL;")},
	}
	err := st.migrate(migrations)
	if err == nil {
		t.Fatal("migrate() returned no error for an invalid migration, want error")
	}
	if !strings.Contains(err.Error(), "apply migration 0001_broken.sql") {
		t.Errorf("migrate() error = %q, want it to name the failing migration", err.Error())
	}
}

// TestOpenFailsWhenSchemaIsAheadOfMigrations simulates a downgrade: a
// database whose schema_migrations already lists a version this binary's
// embedded migrations never reach. Open() must fail loudly through its
// "migrate database" wrapping rather than silently reporting success.
func TestOpenFailsWhenSchemaIsAheadOfMigrations(t *testing.T) {
	dbPath := filepath.Join(t.TempDir(), "test.db")

	db, err := openDB(dbPath)
	if err != nil {
		t.Fatalf("setup: openDB() returned error: %v", err)
	}
	if _, err := db.Exec(`CREATE TABLE schema_migrations (
		version    INTEGER PRIMARY KEY,
		applied_at TEXT NOT NULL
	)`); err != nil {
		t.Fatalf("setup: create schema_migrations failed: %v", err)
	}
	if _, err := db.Exec(
		`INSERT INTO schema_migrations (version, applied_at) VALUES (2, datetime('now'))`,
	); err != nil {
		t.Fatalf("setup: insert future version failed: %v", err)
	}
	if err := db.Close(); err != nil {
		t.Fatalf("setup: close failed: %v", err)
	}

	_, err = Open(dbPath)
	if err == nil {
		t.Fatal("Open() returned no error for a schema ahead of the known migrations, want error")
	}
	if !strings.Contains(err.Error(), "migrate database") {
		t.Errorf("Open() error = %q, want it to wrap the migrate failure", err.Error())
	}
}

// TestOpenDBFailsWhenDirectoryIsUnwritable exercises the Ping() failure
// branch: the directory exists (so MkdirAll is a no-op), but SQLite cannot
// create the database file inside it.
func TestOpenDBFailsWhenDirectoryIsUnwritable(t *testing.T) {
	if os.Geteuid() == 0 {
		t.Skip("running as root ignores permission bits")
	}

	dir := t.TempDir()
	// gosec G302 checks file permissions against a "0600 or less" rule, but
	// this is a directory: the execute bit is what makes it enterable at
	// all, and 0o500 (read+execute, no write) is what we need to test —
	// stricter than 0o700, not laxer, despite tripping the file-oriented
	// check below.
	if err := os.Chmod(dir, 0o500); err != nil { //nolint:gosec // directory perms, not file perms — see comment above
		t.Fatalf("setup: chmod failed: %v", err)
	}
	// Restore write access before t.TempDir()'s own cleanup tries to remove
	// the directory — cleanups run in LIFO order, so this one (registered
	// after Chmod) runs first.
	t.Cleanup(func() { _ = os.Chmod(dir, 0o700) }) //nolint:gosec // directory perms, not file perms — see comment above

	dbPath := filepath.Join(dir, "test.db")
	if _, err := openDB(dbPath); err == nil {
		t.Fatal("openDB() returned no error for an unwritable directory, want error")
	}
}

// TestDBExposesUnderlyingHandle covers the accessor that packages owning
// their own tables (selftest today) rely on.
func TestDBExposesUnderlyingHandle(t *testing.T) {
	st := newTestStore(t)

	if st.DB() == nil {
		t.Fatal("DB() returned nil, want the underlying *sql.DB")
	}
}

// TestSchemaVersionFailsAfterClose covers the error path: a query against a
// closed handle must be reported, not misread as "no migrations applied".
func TestSchemaVersionFailsAfterClose(t *testing.T) {
	dbPath := filepath.Join(t.TempDir(), "test.db")
	st, err := Open(dbPath)
	if err != nil {
		t.Fatalf("Open() returned error: %v", err)
	}
	if err := st.Close(); err != nil {
		t.Fatalf("Close() returned error: %v", err)
	}

	if _, err := st.SchemaVersion(); err == nil {
		t.Fatal("SchemaVersion() after Close() returned no error, want error")
	}
}

// TestAppliedVersionsFailsAfterClose is the same idea one layer down: the
// query migrate() relies on must surface a closed handle as an error too.
func TestAppliedVersionsFailsAfterClose(t *testing.T) {
	st := newTestStore(t)
	if err := st.db.Close(); err != nil {
		t.Fatalf("db.Close() returned error: %v", err)
	}

	if _, _, err := st.appliedVersions(); err == nil {
		t.Fatal("appliedVersions() after Close() returned no error, want error")
	}
}

// TestApplyMigrationRollsBackOnBadSQL ensures a migration whose body does not
// even parse leaves no trace: no schema_migrations row, no partial DDL.
func TestApplyMigrationRollsBackOnBadSQL(t *testing.T) {
	st := newTestStore(t)
	if err := st.migrate(fstest.MapFS{}); err != nil {
		t.Fatalf("setup: migrate() returned error: %v", err)
	}

	if err := st.applyMigration(1, []byte("THIS IS NOT VALID SQL;")); err == nil {
		t.Fatal("applyMigration() returned no error for invalid SQL, want error")
	}

	version, err := st.SchemaVersion()
	if err != nil {
		t.Fatalf("SchemaVersion() returned error: %v", err)
	}
	if version != 0 {
		t.Errorf("SchemaVersion() after rolled-back applyMigration() = %d, want 0", version)
	}
}

// TestApplyMigrationRollsBackOnDuplicateVersion covers the other failure
// branch: the migration body itself succeeds (it is idempotent), but
// recording it under a version already present in schema_migrations collides
// with the primary key. Neither half must stick.
func TestApplyMigrationRollsBackOnDuplicateVersion(t *testing.T) {
	st := newTestStore(t)
	if err := st.migrate(fstest.MapFS{}); err != nil {
		t.Fatalf("setup: migrate() returned error: %v", err)
	}

	body := []byte(`CREATE TABLE IF NOT EXISTS idempotent_table (id TEXT);`)
	if err := st.applyMigration(1, body); err != nil {
		t.Fatalf("first applyMigration() returned error: %v", err)
	}

	if err := st.applyMigration(1, body); err == nil {
		t.Fatal("second applyMigration() with a duplicate version returned no error, want error")
	}

	version, err := st.SchemaVersion()
	if err != nil {
		t.Fatalf("SchemaVersion() returned error: %v", err)
	}
	if version != 1 {
		t.Errorf("SchemaVersion() after rejected duplicate applyMigration() = %d, want 1 (unchanged)", version)
	}
}

// TestMigrationVersionRejectsEmptyPrefix covers a filename that starts with
// the underscore itself, e.g. "_foo.sql" — Cut then yields an empty prefix, a
// different failure mode than a prefix present but non-numeric.
func TestMigrationVersionRejectsEmptyPrefix(t *testing.T) {
	if _, err := migrationVersion("_no_prefix.sql"); err == nil {
		t.Fatal("migrationVersion() returned no error for an empty numeric prefix, want error")
	}
}

// TestMigrationVersionRejectsOverflowingPrefix covers a numeric prefix too
// large for an int — every character is a digit, so the loop accepts it, but
// strconv.Atoi itself must still reject it rather than wrap around silently.
func TestMigrationVersionRejectsOverflowingPrefix(t *testing.T) {
	name := "999999999999999999999999999999_overflow.sql"
	if _, err := migrationVersion(name); err == nil {
		t.Fatal("migrationVersion() returned no error for an overflowing numeric prefix, want error")
	}
}
