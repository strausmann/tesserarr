package api

import (
	"io"
	"io/fs"
	"net/http"
)

// newStaticHandler serves the embedded browser interface (internal/web.FS)
// over HTTP. Registered at "/" — the lowest-specificity pattern
// net/http.ServeMux knows — it is a catch-all in name only: every other
// route this package registers (/api/v1/..., /healthz, /invite/{token}) is
// a more specific pattern, and ServeMux always resolves the most specific
// match regardless of registration order (see the package comment on
// router.go). Nothing under those prefixes can ever reach this handler.
//
// An unknown path answers with the interface's OWN compiled 404 page —
// Next's static export writes one to 404.html at the output root
// specifically so a static host can serve it — at HTTP 404, never the
// catalog's index.html at 200. This app is not a client-routed
// single-page app where an unknown URL is still something the in-browser
// router might resolve once index.html's JavaScript runs: each of its
// three routes (/, /login/, /logout/) is its own separately built,
// separately served page (see web/next.config.ts's trailingSlash
// comment). Answering an unknown path with the catalog page's content at
// 200 would silently run ITS OWN logic (fetchSessionStatus, its own
// redirect-if-unauthenticated) at a URL that has nothing to do with the
// catalog — misleading, and simply wrong for what this export actually is.
func newStaticHandler(fsys fs.FS) http.HandlerFunc {
	fileServer := http.FileServerFS(fsys)

	return func(w http.ResponseWriter, r *http.Request) {
		nfw := &notFoundInterceptingWriter{ResponseWriter: w}
		fileServer.ServeHTTP(nfw, r)
		if nfw.intercepted {
			serveEmbeddedNotFoundPage(w, r, fsys)
		}
	}
}

// notFoundInterceptingWriter lets fileServer.ServeHTTP write its normal
// response for everything it finds, but swallows the moment it decides a
// path does not exist — http.FileServerFS answers that case with
// http.Error(w, "...", http.StatusNotFound), which this type recognizes by
// its status code alone, not by string-matching the body. Swallowing
// rather than passing it through is what lets serveEmbeddedNotFoundPage
// substitute the interface's own 404.html afterwards, without
// reimplementing http.FileServerFS's own directory-index and
// trailing-slash-redirect handling (both of which trailingSlash: true in
// next.config.ts specifically relies on) just to add a custom 404 page in
// front of it — the standard way to layer one over
// http.FileServer/http.FileServerFS.
type notFoundInterceptingWriter struct {
	http.ResponseWriter
	intercepted bool
}

func (w *notFoundInterceptingWriter) WriteHeader(status int) {
	if status == http.StatusNotFound {
		// fileServer has not written any body yet at this point — only
		// called Header().Set for its own Content-Type/X-Content-Type-Options,
		// which serveEmbeddedNotFoundPage below overwrites where it
		// matters (Content-Type) and otherwise leaves harmlessly in place.
		w.intercepted = true
		return
	}
	w.ResponseWriter.WriteHeader(status)
}

func (w *notFoundInterceptingWriter) Write(b []byte) (int, error) {
	if w.intercepted {
		// Discarded: fileServer's own "404 page not found\n" body, which
		// serveEmbeddedNotFoundPage replaces with the real 404.html.
		// Reporting len(b) rather than 0 keeps this a well-behaved
		// io.Writer for fileServer's own error handling, which does not
		// itself check Write's return value here.
		return len(b), nil
	}
	return w.ResponseWriter.Write(b)
}

// serveEmbeddedNotFoundPage writes fsys's own 404.html at HTTP 404. If the
// embedded interface carries no 404.html of its own — the placeholder
// checked into the repository does not, only a real build does (see
// internal/web/dist/index.html's own comment) — it falls back to
// net/http's plain 404 rather than erroring out over a missing file that
// was never guaranteed to be there.
func serveEmbeddedNotFoundPage(w http.ResponseWriter, r *http.Request, fsys fs.FS) {
	f, err := fsys.Open("404.html")
	if err != nil {
		http.NotFound(w, r)
		return
	}
	defer func() { _ = f.Close() }()

	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.WriteHeader(http.StatusNotFound)
	_, _ = io.Copy(w, f)
}
