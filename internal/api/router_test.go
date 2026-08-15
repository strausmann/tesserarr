package api

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"

	"github.com/strausmann/tesserarr/internal/config"
	"github.com/strausmann/tesserarr/internal/store"
)

// newTestAPI builds an API against a fresh, disposable fake OIDC provider
// (see oidc_fake_provider_test.go) — New always discovers a provider as
// part of building the API (see its comment in router.go), so every test in
// this package needs one reachable, whether or not the test actually
// exercises a login.
func newTestAPI(t *testing.T) *API {
	t.Helper()

	provider := newFakeOIDCProvider(t)

	cfg := &config.Config{
		HTTPAddr:          "127.0.0.1:0",
		BaseURL:           "https://tesserarr.example.org",
		DataDir:           t.TempDir(),
		SessionCookieName: "tesserarr_session",
		LogLevel:          "info",
		OIDCIssuerURL:     provider.issuer(),
		OIDCClientID:      fakeOIDCClientID,
		OIDCClientSecret:  config.Secret("test-client-secret-does-not-matter"),
	}
	cfg.DBPath = filepath.Join(cfg.DataDir, "test.db")

	st, err := store.Open(cfg.DBPath)
	if err != nil {
		t.Fatalf("store.Open() returned error: %v", err)
	}
	t.Cleanup(func() { _ = st.Close() })

	a, err := New(context.Background(), cfg, st)
	if err != nil {
		t.Fatalf("New() returned error: %v", err)
	}
	return a
}

// loginAsTestUser wraps createTestUserAndSession (middleware_test.go) —
// which already does the actual work of inserting the minimal user row and
// minting a real session via a.sessions, the same store requireSession
// resolves against — and hands back a ready-to-attach *http.Cookie instead
// of the raw token string. tokens_test.go and Aufgabe 8's account_test.go
// both need exactly that shape to exercise session-space handlers via
// req.AddCookie, without going through a full OIDC or forward-auth round
// trip; delegating here keeps the user-row-plus-session-creation logic in
// the one place middleware_test.go already owns it, rather than
// re-implementing it a second time.
func loginAsTestUser(t *testing.T, a *API, userID string) *http.Cookie {
	t.Helper()

	token := createTestUserAndSession(t, a, userID)
	return &http.Cookie{Name: a.cfg.SessionCookieName, Value: token} //nolint:gosec // G124: this is an INCOMING request cookie built for a test client — see the comment on the other AddCookie calls in middleware_test.go/csrf_test.go
}

// countingReader wraps a reader and tallies how many bytes were actually
// pulled out of it. account_test.go's and tokens_test.go's oversized-body
// tests use it to prove a request-body-size guard rejects an oversized
// request WITHOUT first reading the whole thing into memory — the point of
// http.MaxBytesReader is that it stops the caller from ever being handed the
// rest of an over-limit body, not merely that decoding eventually fails. A
// test that only checked the resulting status code could not tell "the guard
// engaged early" apart from "the body was read in full and something else
// downstream rejected it".
type countingReader struct {
	r    io.Reader
	read int64
}

func (c *countingReader) Read(p []byte) (int, error) {
	n, err := c.r.Read(p)
	c.read += int64(n)
	return n, err
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

// TestRootServesEmbeddedInterface proves New actually wires the embedded
// web interface (internal/web.FS, see static.go) into the running server at
// "/" — not just that newStaticHandler works in isolation (static_test.go
// already covers that against a fstest.MapFS).
func TestRootServesEmbeddedInterface(t *testing.T) {
	a := newTestAPI(t)

	req := httptest.NewRequest(http.MethodGet, "/", nil)
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusOK)
	}
	if ct := rec.Header().Get("Content-Type"); !strings.HasPrefix(ct, "text/html") {
		t.Errorf("Content-Type = %q, want a text/html prefix", ct)
	}
	if rec.Body.Len() == 0 {
		t.Error("response body is empty")
	}
}

// TestStaticRouteDoesNotShadowAPIRoutes is the ordering guarantee the
// static catch-all at "/" (see static.go, and its own comment in New,
// router.go) depends on: net/http.ServeMux resolves every request to its
// MOST SPECIFIC matching pattern, so /api/v1/... always wins over "/"
// regardless of registration order. A session-space request with no cookie
// must still get requireSession's 401 — proof it never reaches the static
// handler's own 404 page instead, which would look like "route
// disappeared" rather than "credential missing".
func TestStaticRouteDoesNotShadowAPIRoutes(t *testing.T) {
	a := newTestAPI(t)

	req := httptest.NewRequest(http.MethodGet, PrefixApp+"/session", nil)
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("status = %d, want %d — the static route at \"/\" swallowed a session-space request", rec.Code, http.StatusUnauthorized)
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
