// Package store owns the SQLite database: opening it, running migrations and
// handing out the connection. No other package opens the database directly.
package store

import (
	"database/sql"
	"embed"
	"fmt"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strconv"
	"strings"

	_ "modernc.org/sqlite" // pure-Go driver: CGO_ENABLED=0 is a hard requirement
)

//go:embed migrations/*.sql
var migrationFS embed.FS

// Store wraps the database handle and the migration machinery.
type Store struct {
	db *sql.DB
}

// Open opens (and if necessary creates) the database at dbPath, then applies
// every migration that has not run yet. It is safe to call on an existing
// database: already applied migrations are skipped.
func Open(dbPath string) (*Store, error) {
	db, err := openDB(dbPath)
	if err != nil {
		return nil, err
	}

	sub, err := fs.Sub(migrationFS, "migrations")
	if err != nil {
		_ = db.Close()
		return nil, fmt.Errorf("prepare embedded migrations: %w", err)
	}

	st := &Store{db: db}
	if err := st.migrate(sub); err != nil {
		_ = db.Close()
		return nil, fmt.Errorf("migrate database: %w", err)
	}
	return st, nil
}

// openDB opens the raw database handle, creating the parent directory and
// pinging the connection so a bad path fails here rather than on first query.
func openDB(dbPath string) (*sql.DB, error) {
	if err := os.MkdirAll(filepath.Dir(dbPath), 0o750); err != nil {
		return nil, fmt.Errorf("create database directory: %w", err)
	}

	// _pragma parameters are driver-level settings. foreign_keys is off by
	// default in SQLite; without it the REFERENCES clauses above are decoration.
	dsn := dbPath + "?_pragma=foreign_keys(1)&_pragma=journal_mode(WAL)&_pragma=busy_timeout(5000)"
	db, err := sql.Open("sqlite", dsn)
	if err != nil {
		return nil, fmt.Errorf("open database: %w", err)
	}
	if err := db.Ping(); err != nil {
		_ = db.Close()
		return nil, fmt.Errorf("reach database: %w", err)
	}
	return db, nil
}

// Close releases the database handle.
func (s *Store) Close() error { return s.db.Close() }

// DB exposes the handle for packages that own their own tables.
func (s *Store) DB() *sql.DB { return s.db }

// SchemaVersion reports the highest migration that has been applied.
func (s *Store) SchemaVersion() (int, error) {
	var version int
	err := s.db.QueryRow(`SELECT COALESCE(MAX(version), 0) FROM schema_migrations`).Scan(&version)
	if err != nil {
		return 0, fmt.Errorf("read schema version: %w", err)
	}
	return version, nil
}

// namedMigration pairs a migration's parsed version with the file that
// defines it, so the two travel together once the file list has been sorted
// into application order.
type namedMigration struct {
	version int
	name    string
}

// migrate applies every migration in migrations that schema_migrations does
// not yet list as applied. The version comes from the filename, never from
// where the file happens to sort in a directory listing: a file that sorts
// or is discovered before an already-applied one must not shift what
// "version 1" means, or re-run a migration under a version number that was
// never its own.
func (s *Store) migrate(migrations fs.FS) error {
	_, err := s.db.Exec(`CREATE TABLE IF NOT EXISTS schema_migrations (
		version    INTEGER PRIMARY KEY,
		applied_at TEXT NOT NULL
	)`)
	if err != nil {
		return fmt.Errorf("create migration table: %w", err)
	}

	entries, err := fs.ReadDir(migrations, ".")
	if err != nil {
		return fmt.Errorf("read migrations: %w", err)
	}

	byVersion := make(map[int]string, len(entries))
	parsed := make([]namedMigration, 0, len(entries))
	for _, entry := range entries {
		if entry.IsDir() {
			continue
		}
		version, err := migrationVersion(entry.Name())
		if err != nil {
			return fmt.Errorf("migration %s: %w", entry.Name(), err)
		}
		if other, ok := byVersion[version]; ok {
			return fmt.Errorf("migration version %d is defined twice: %s and %s", version, other, entry.Name())
		}
		byVersion[version] = entry.Name()
		parsed = append(parsed, namedMigration{version: version, name: entry.Name()})
	}
	sort.Slice(parsed, func(i, j int) bool { return parsed[i].version < parsed[j].version })

	applied, highest, err := s.appliedVersions()
	if err != nil {
		return err
	}

	for _, m := range parsed {
		if applied[m.version] {
			continue
		}
		if m.version < highest {
			return fmt.Errorf(
				"migration %s has version %d, which is lower than the highest already-applied version %d: refusing to apply it out of order",
				m.name, m.version, highest,
			)
		}

		body, err := fs.ReadFile(migrations, m.name)
		if err != nil {
			return fmt.Errorf("read migration %s: %w", m.name, err)
		}
		if err := s.applyMigration(m.version, body); err != nil {
			return fmt.Errorf("apply migration %s: %w", m.name, err)
		}
	}
	return nil
}

// applyMigration runs one migration's SQL and records it as applied in the
// same transaction, so a failure never leaves the database half-migrated.
func (s *Store) applyMigration(version int, body []byte) error {
	tx, err := s.db.Begin()
	if err != nil {
		return fmt.Errorf("begin: %w", err)
	}
	if _, err := tx.Exec(string(body)); err != nil {
		_ = tx.Rollback()
		return fmt.Errorf("exec: %w", err)
	}
	if _, err := tx.Exec(
		`INSERT INTO schema_migrations (version, applied_at) VALUES (?, datetime('now'))`,
		version,
	); err != nil {
		_ = tx.Rollback()
		return fmt.Errorf("record: %w", err)
	}
	if err := tx.Commit(); err != nil {
		return fmt.Errorf("commit: %w", err)
	}
	return nil
}

// appliedVersions reads which migration versions schema_migrations already
// lists, along with the highest of them (0 if none are applied yet).
func (s *Store) appliedVersions() (map[int]bool, int, error) {
	rows, err := s.db.Query(`SELECT version FROM schema_migrations`)
	if err != nil {
		return nil, 0, fmt.Errorf("read applied migrations: %w", err)
	}
	defer rows.Close()

	applied := make(map[int]bool)
	highest := 0
	for rows.Next() {
		var version int
		if err := rows.Scan(&version); err != nil {
			return nil, 0, fmt.Errorf("scan applied migration: %w", err)
		}
		applied[version] = true
		if version > highest {
			highest = version
		}
	}
	if err := rows.Err(); err != nil {
		return nil, 0, fmt.Errorf("iterate applied migrations: %w", err)
	}
	return applied, highest, nil
}

// migrationVersion parses the version from a migration filename: the leading
// run of digits before the first underscore, e.g. "0001_initial.sql" -> 1. A
// filename without that numeric prefix is a configuration error, not
// something to silently skip past or assign a made-up version to.
func migrationVersion(name string) (int, error) {
	base := strings.TrimSuffix(name, path.Ext(name))
	prefix, _, _ := strings.Cut(base, "_")
	if prefix == "" {
		return 0, fmt.Errorf("filename %q has no numeric version prefix", name)
	}
	for _, r := range prefix {
		if r < '0' || r > '9' {
			return 0, fmt.Errorf("filename %q has no numeric version prefix", name)
		}
	}
	version, err := strconv.Atoi(prefix)
	if err != nil {
		return 0, fmt.Errorf("filename %q has no numeric version prefix", name)
	}
	return version, nil
}
