package api

import (
	"net/http"
	"net/http/httptest"
	"testing"
	"testing/fstest"
)

func TestStaticHandlerServesIndexHTML(t *testing.T) {
	fsys := fstest.MapFS{
		"index.html": {Data: []byte("<html>catalog</html>")},
	}
	handler := newStaticHandler(fsys)

	req := httptest.NewRequest(http.MethodGet, "/", nil)
	rec := httptest.NewRecorder()
	handler.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusOK)
	}
	if got := rec.Body.String(); got != "<html>catalog</html>" {
		t.Errorf("body = %q, want %q", got, "<html>catalog</html>")
	}
	if ct := rec.Header().Get("Content-Type"); ct != "text/html; charset=utf-8" {
		t.Errorf("Content-Type = %q, want %q", ct, "text/html; charset=utf-8")
	}
}

// TestStaticHandlerServesNestedIndexHTML matches how the real export lays
// out its other two routes (see web/next.config.ts's trailingSlash
// comment): "/login/" resolves to "login/index.html" in the embedded FS,
// exactly the way http.FileServerFS resolves any directory request.
func TestStaticHandlerServesNestedIndexHTML(t *testing.T) {
	fsys := fstest.MapFS{
		"index.html":       {Data: []byte("catalog")},
		"login/index.html": {Data: []byte("<html>login</html>")},
	}
	handler := newStaticHandler(fsys)

	req := httptest.NewRequest(http.MethodGet, "/login/", nil)
	rec := httptest.NewRecorder()
	handler.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusOK)
	}
	if got := rec.Body.String(); got != "<html>login</html>" {
		t.Errorf("body = %q, want %q", got, "<html>login</html>")
	}
}

// TestStaticHandlerReturnsEmbeddedNotFoundPageWithStatus404 is the
// deliberate choice the team lead asked to have justified, not just made:
// an unknown path gets the embedded interface's OWN compiled 404 page (the
// one Next's static export writes to 404.html specifically for static
// hosts to use), not the catalog's index.html. This app is three separately
// built static pages, not a client-routed single-page app where falling
// back to index.html would let an in-browser router still resolve the URL
// — there is no such router here for an unknown path, so index.html would
// just be the wrong page rendered at the wrong URL.
func TestStaticHandlerReturnsEmbeddedNotFoundPageWithStatus404(t *testing.T) {
	fsys := fstest.MapFS{
		"index.html": {Data: []byte("<html>catalog</html>")},
		"404.html":   {Data: []byte("<html>not here</html>")},
	}
	handler := newStaticHandler(fsys)

	req := httptest.NewRequest(http.MethodGet, "/does-not-exist", nil)
	rec := httptest.NewRecorder()
	handler.ServeHTTP(rec, req)

	if rec.Code != http.StatusNotFound {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusNotFound)
	}
	if got := rec.Body.String(); got != "<html>not here</html>" {
		t.Errorf("body = %q, want the embedded 404.html content, got %q", got, "<html>not here</html>")
	}
	if ct := rec.Header().Get("Content-Type"); ct != "text/html; charset=utf-8" {
		t.Errorf("Content-Type = %q, want %q", ct, "text/html; charset=utf-8")
	}
}

// TestStaticHandlerFallsBackToPlainNotFoundWithoutEmbedded404Page covers
// the defensive branch: an embedded FS that, for whatever reason, carries
// no 404.html of its own must still answer with a 404 status rather than
// error out trying to open a file that is not there.
func TestStaticHandlerFallsBackToPlainNotFoundWithoutEmbedded404Page(t *testing.T) {
	fsys := fstest.MapFS{
		"index.html": {Data: []byte("<html>catalog</html>")},
	}
	handler := newStaticHandler(fsys)

	req := httptest.NewRequest(http.MethodGet, "/does-not-exist", nil)
	rec := httptest.NewRecorder()
	handler.ServeHTTP(rec, req)

	if rec.Code != http.StatusNotFound {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusNotFound)
	}
}
