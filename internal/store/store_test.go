package store

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"testing/fstest"
)

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
	if version != 1 {
		t.Errorf("SchemaVersion() = %d, want 1", version)
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
	if version != 1 {
		t.Errorf("SchemaVersion() after reopen = %d, want 1", version)
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
