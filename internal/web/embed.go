// Package web embeds the browser interface's static export (ADR-0005:
// React/Next, built with `output: 'export'`, no Node runtime in the shipped
// image) into the Go binary, so the running server carries the whole
// interface with it — no separate volume, no separate process, nothing that
// can drift out of sync with the binary serving it.
//
// dist/ ships in the repository with only a placeholder page (see its own
// comment) so `go build ./...` and `go test ./...` succeed in a completely
// fresh clone, with no Node/pnpm step required — the whole point being that
// a bare Go CI job (.github/workflows/test.yml) never has to know this
// package's directory exists to build the module. The REAL interface lands
// there only during deploy/Dockerfile's build: its "web" stage runs
// `pnpm --dir web build`, producing web/out, and its "build" stage's
// `COPY --from=web /src/web/out ./internal/web/dist` overwrites the
// placeholder before `go build` ever runs — entirely inside the Docker
// build context, never touching a developer's actual working tree.
//
// Nothing here reaches into web/out directly, because go:embed patterns
// cannot cross outside the embedding file's own package directory (no ".."
// path elements) — dist has to live inside this package for the directive
// below to reach it at all.
package web

import (
	"embed"
	"io/fs"
)

//go:embed all:dist
var distFS embed.FS

// FS returns the embedded static export, rooted at its own top level —
// callers see "index.html", not "dist/index.html". Wrapping the embed.FS in
// fs.Sub here, once, is what lets whatever in internal/api later serves this
// over HTTP stay ignorant of "dist" being this package's chosen directory
// name for it.
func FS() (fs.FS, error) {
	return fs.Sub(distFS, "dist")
}
