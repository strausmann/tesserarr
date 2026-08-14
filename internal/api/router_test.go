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

// TestHealthEndpointReturnsServiceUnavailableWhenDatabaseIsUnreachable closes
// the store's connection out from under the handler, which is exactly how a
// lost database connection presents to PingContext in production.
func TestHealthEndpointReturnsServiceUnavailableWhenDatabaseIsUnreachable(t *testing.T) {
	a := newTestAPI(t)
	if err := a.store.Close(); err != nil {
		t.Fatalf("store.Close() returned error: %v", err)
	}

	req := httptest.NewRequest(http.MethodGet, "/healthz", nil)
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusServiceUnavailable {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusServiceUnavailable)
	}
	if ct := rec.Header().Get("Content-Type"); ct != "application/json" {
		t.Errorf("Content-Type = %q, want application/json", ct)
	}
}

func TestHealthEndpointCarriesNoAuthMiddleware(t *testing.T) {
	// The health path must stay reachable without any credential — a monitoring
	// probe has none. This is the one deliberate exception to the two prefixes.
	a := newTestAPI(t)

	for _, route := range a.Routes() {
		if route.Pattern == "/healthz" && route.Middleware != MiddlewarePublic {
			t.Errorf("health route carries middleware %v, want %v", route.Middleware, MiddlewarePublic)
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

func TestMiddlewareStringUnknownValue(t *testing.T) {
	// Middleware is a closed set today, but String() still has to answer
	// something sane for a value outside it rather than panic or print
	// garbage — this is what a stray fmt.Sprintf("%v") would hit.
	if got := Middleware(99).String(); got != "unknown" {
		t.Errorf("String() = %q, want %q", got, "unknown")
	}
}

func TestRequireSessionRejectsEverything(t *testing.T) {
	// No cookie at all is the un-authenticated baseline: it must be rejected
	// regardless of what else requireSession later learns to accept. The
	// credential-bearing cases (valid cookie, valid-but-misplaced bearer
	// token, unknown cookie) live in middleware_test.go.
	a := newTestAPI(t)
	handler := a.requireSession(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Fatal("wrapped handler must not run before session authentication exists")
	}))

	req := httptest.NewRequest(http.MethodGet, PrefixApp+"/whatever", nil)
	rec := httptest.NewRecorder()
	handler.ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusUnauthorized)
	}
}

func TestRequireTokenRejectsEverything(t *testing.T) {
	// No Authorization header at all is the un-authenticated baseline — see
	// the comment on TestRequireSessionRejectsEverything above.
	a := newTestAPI(t)
	handler := a.requireToken(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Fatal("wrapped handler must not run before token authentication exists")
	}))

	req := httptest.NewRequest(http.MethodGet, PrefixToken+"/whatever", nil)
	rec := httptest.NewRecorder()
	handler.ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusUnauthorized)
	}
}

// TestWrapSelectsMiddlewareByKind exercises all three branches of wrap()
// directly with an unauthenticated request, independent of which concrete
// routes New() happens to register them on.
func TestWrapSelectsMiddlewareByKind(t *testing.T) {
	a := newTestAPI(t)
	inner := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusTeapot)
	})

	tests := []struct {
		name string
		mw   Middleware
		want int
	}{
		{"session rejects", MiddlewareSession, http.StatusUnauthorized},
		{"token rejects", MiddlewareToken, http.StatusUnauthorized},
		{"public passes through", MiddlewarePublic, http.StatusTeapot},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			handler := a.wrap(tt.mw, inner)

			req := httptest.NewRequest(http.MethodGet, "/whatever", nil)
			rec := httptest.NewRecorder()
			handler.ServeHTTP(rec, req)

			if rec.Code != tt.want {
				t.Errorf("status = %d, want %d", rec.Code, tt.want)
			}
		})
	}
}
