// Package web embeds the browser interface's static export into the Go
// binary via go:embed, so the running server has no runtime dependency on
// anything outside itself (ADR-0005). See embed.go's own comment for how
// dist/ gets populated with the REAL build during a Docker build, and why a
// placeholder lives there in the repository itself.
package web

import (
	"io"
	"testing"
)

func TestFSContainsANonEmptyIndexHTML(t *testing.T) {
	fsys, err := FS()
	if err != nil {
		t.Fatalf("FS() returned error: %v", err)
	}

	f, err := fsys.Open("index.html")
	if err != nil {
		t.Fatalf("Open(\"index.html\") returned error: %v", err)
	}
	defer func() {
		if err := f.Close(); err != nil {
			t.Errorf("Close() returned error: %v", err)
		}
	}()

	content, err := io.ReadAll(f)
	if err != nil {
		t.Fatalf("ReadAll on index.html returned error: %v", err)
	}
	if len(content) == 0 {
		t.Fatal("index.html is present but empty")
	}
}

// TestFSIsRootedAtDistNotAtItsParent guards against the single easiest
// mistake in wiring embed.FS's Sub call: forgetting it, or applying it to
// the wrong prefix, leaves callers having to know the internal "dist"
// directory name — which is exactly the detail FS() exists to hide from
// whatever in internal/api later serves this over HTTP.
func TestFSIsRootedAtDistNotAtItsParent(t *testing.T) {
	fsys, err := FS()
	if err != nil {
		t.Fatalf("FS() returned error: %v", err)
	}

	if _, err := fsys.Open("dist/index.html"); err == nil {
		t.Fatal(`Open("dist/index.html") succeeded — FS() is rooted one level too high`)
	}
}
