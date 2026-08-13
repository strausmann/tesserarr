package store

import (
	"os"
	"path/filepath"
	"testing"
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
