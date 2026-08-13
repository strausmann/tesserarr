package api

import (
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"testing"

	"github.com/strausmann/tesserarr/internal/config"
	"github.com/strausmann/tesserarr/internal/store"
)

func newTestAPI(t *testing.T) *API {
	t.Helper()

	cfg := &config.Config{
		HTTPAddr:          "127.0.0.1:0",
		BaseURL:           "https://tesserarr.example.org",
		DataDir:           t.TempDir(),
		SessionCookieName: "tesserarr_session",
		LogLevel:          "info",
	}
	cfg.DBPath = filepath.Join(cfg.DataDir, "test.db")

	st, err := store.Open(cfg.DBPath)
	if err != nil {
		t.Fatalf("store.Open() returned error: %v", err)
	}
	t.Cleanup(func() { _ = st.Close() })

	return New(cfg, st)
}

func TestHealthEndpointReturnsOK(t *testing.T) {
	a := newTestAPI(t)

	req := httptest.NewRequest(http.MethodGet, "/healthz", nil)
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusOK)
	}
}

func TestHealthEndpointCarriesNoAuthMiddleware(t *testing.T) {
	// The health path must stay reachable without any credential — a monitoring
	// probe has none. This is the one deliberate exception to the two prefixes.
	a := newTestAPI(t)

	for _, route := range a.Routes() {
		if route.Pattern == "/healthz" && route.Middleware != MiddlewareNone {
			t.Errorf("health route carries middleware %v, want %v", route.Middleware, MiddlewareNone)
		}
	}
}

func TestUnknownPathReturnsNotFound(t *testing.T) {
	a := newTestAPI(t)

	req := httptest.NewRequest(http.MethodGet, "/does-not-exist", nil)
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusNotFound {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusNotFound)
	}
}
