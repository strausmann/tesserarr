package api

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// createTestUserAndSession inserts the minimal user row the sessions table's
// foreign key requires, then mints a valid session token for it.
func createTestUserAndSession(t *testing.T, a *API, userID string) string {
	t.Helper()

	_, err := a.store.DB().Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		userID, "test user", time.Now().UTC().Format(time.RFC3339),
	)
	if err != nil {
		t.Fatalf("insert test user: %v", err)
	}

	token, err := a.sessions.Create(userID)
	if err != nil {
		t.Fatalf("create session: %v", err)
	}
	return token
}

// createTestUserAndAPIToken inserts the minimal user row api_tokens' foreign
// key requires, then mints a valid API token for it via a.apiTokens — the
// second, independent store TestSessionTokenAsBearerIsRejected and
// TestAPITokenAsSessionCookieIsRejected exist to prove requireSession and
// requireToken each resolve their own credential kind against, and neither
// other's.
func createTestUserAndAPIToken(t *testing.T, a *API, userID string) string {
	t.Helper()

	_, err := a.store.DB().Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		userID, "test user", time.Now().UTC().Format(time.RFC3339),
	)
	if err != nil {
		t.Fatalf("insert test user: %v", err)
	}

	// ttl 0 means "no expiry" (apitoken.Store.Create): these helpers hand out a
	// credential for the duration of one test, and an expiry would only add a
	// clock the test does not need. The second return value is the stored
	// token's metadata, which no caller here inspects.
	token, _, err := a.apiTokens.Create(userID, "test token", 0)
	if err != nil {
		t.Fatalf("create api token: %v", err)
	}
	return token
}

// TestSessionTokenAsBearerIsRejected is the second barrier ADR-0007 and the
// concept's "Anmeldung und Sitzungen" section require: a session token must
// not authenticate the token path, even sent exactly the way a bearer token
// is expected — because requireToken resolves it against a.apiTokens, a
// store a session token was never written into. Before requireToken was
// switched off a.sessions, this test failed: both middlewares resolved
// against the same store, so a session token really did double as a bearer
// token (see the now-removed interim comment this change replaced).
func TestSessionTokenAsBearerIsRejected(t *testing.T) {
	a := newTestAPI(t)
	sessionToken := createTestUserAndSession(t, a, "user-1")

	req := httptest.NewRequest(http.MethodGet, PrefixToken+"/status", nil)
	req.Header.Set("Authorization", "Bearer "+sessionToken)
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("status = %d, want %d (a valid session token must not authenticate the token path)",
			rec.Code, http.StatusUnauthorized)
	}
}

// TestAPITokenAsSessionCookieIsRejected is the other half of the same
// barrier: an API token must not authenticate the session path, even sent
// exactly the way a session cookie is expected — because requireSession
// resolves it against a.sessions, a store an API token was never written
// into.
func TestAPITokenAsSessionCookieIsRejected(t *testing.T) {
	a := newTestAPI(t)
	apiToken := createTestUserAndAPIToken(t, a, "user-1")

	req := httptest.NewRequest(http.MethodGet, PrefixApp+"/session", nil)
	req.AddCookie(&http.Cookie{Name: a.cfg.SessionCookieName, Value: apiToken}) //nolint:gosec // G124: incoming request cookie for a test client — see the comment on the other AddCookie calls in this file
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("status = %d, want %d (a valid API token must not authenticate the session path)",
			rec.Code, http.StatusUnauthorized)
	}
}

// TestTokenPathRejectsValidSessionCookieWithoutHeader is the first half of the
// symmetry ADR-0007 requires: the token path must never look at the session
// cookie, no matter how valid it is.
func TestTokenPathRejectsValidSessionCookieWithoutHeader(t *testing.T) {
	a := newTestAPI(t)
	token := createTestUserAndSession(t, a, "user-1")

	req := httptest.NewRequest(http.MethodGet, PrefixToken+"/status", nil)
	req.AddCookie(&http.Cookie{Name: a.cfg.SessionCookieName, Value: token}) //nolint:gosec // G124: this is an INCOMING request cookie built for a test client — AddCookie only ever serializes Name/Value onto the Cookie header, so Secure/HttpOnly/SameSite (response-cookie attributes) do not apply here
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("status = %d, want %d (a valid session cookie must not authenticate the token path)",
			rec.Code, http.StatusUnauthorized)
	}
}

// TestSessionPathRejectsValidBearerTokenWithoutCookie is the symmetric half.
// It gets its own test rather than reusing the previous one in a loop: a
// shared "cookie OR header" helper would pass the first test and fail this
// one, and only the pair together proves that no such helper exists here.
func TestSessionPathRejectsValidBearerTokenWithoutCookie(t *testing.T) {
	a := newTestAPI(t)
	token := createTestUserAndSession(t, a, "user-1")

	req := httptest.NewRequest(http.MethodGet, PrefixApp+"/session", nil)
	req.Header.Set("Authorization", "Bearer "+token)
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("status = %d, want %d (a valid bearer token must not authenticate the session path)",
			rec.Code, http.StatusUnauthorized)
	}
}

// TestEachPathAcceptsOnlyItsOwnCredential is the positive counterpart to the
// rejection tests in this file: sent the way each path expects, its own
// credential kind — a session token from a.sessions on the cookie path, an
// API token from a.apiTokens on the header path — authenticates it. Two
// different credentials, deliberately, not one reused both ways: since
// requireToken switched off the shared identity.SessionStore lookup (see
// TestSessionTokenAsBearerIsRejected), a session token no longer
// authenticates the token path at all, so proving both paths still work at
// all now requires minting from each store in turn.
func TestEachPathAcceptsOnlyItsOwnCredential(t *testing.T) {
	a := newTestAPI(t)

	t.Run("session path accepts its own cookie", func(t *testing.T) {
		sessionToken := createTestUserAndSession(t, a, "user-1")

		req := httptest.NewRequest(http.MethodGet, PrefixApp+"/session", nil)
		req.AddCookie(&http.Cookie{Name: a.cfg.SessionCookieName, Value: sessionToken}) //nolint:gosec // G124: this is an INCOMING request cookie built for a test client — AddCookie only ever serializes Name/Value onto the Cookie header, so Secure/HttpOnly/SameSite (response-cookie attributes) do not apply here
		rec := httptest.NewRecorder()
		a.Handler().ServeHTTP(rec, req)

		if rec.Code != http.StatusOK {
			t.Fatalf("status = %d, want %d", rec.Code, http.StatusOK)
		}
		var body map[string]string
		if err := json.NewDecoder(rec.Body).Decode(&body); err != nil {
			t.Fatalf("decode body: %v", err)
		}
		if body["user_id"] != "user-1" {
			t.Errorf("user_id = %q, want %q", body["user_id"], "user-1")
		}
	})

	t.Run("token path accepts its own header", func(t *testing.T) {
		apiToken := createTestUserAndAPIToken(t, a, "user-2")

		req := httptest.NewRequest(http.MethodGet, PrefixToken+"/status", nil)
		req.Header.Set("Authorization", "Bearer "+apiToken)
		rec := httptest.NewRecorder()
		a.Handler().ServeHTTP(rec, req)

		if rec.Code != http.StatusOK {
			t.Fatalf("status = %d, want %d", rec.Code, http.StatusOK)
		}
		var body map[string]string
		if err := json.NewDecoder(rec.Body).Decode(&body); err != nil {
			t.Fatalf("decode body: %v", err)
		}
		if body["user_id"] != "user-2" {
			t.Errorf("user_id = %q, want %q", body["user_id"], "user-2")
		}
	})
}

func TestRequireSessionRejectsUnknownCookie(t *testing.T) {
	a := newTestAPI(t)

	req := httptest.NewRequest(http.MethodGet, PrefixApp+"/session", nil)
	req.AddCookie(&http.Cookie{Name: a.cfg.SessionCookieName, Value: "token-that-was-never-created"}) //nolint:gosec // G124: incoming request cookie for a test client — see the comment on the other AddCookie calls in this file
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusUnauthorized)
	}
}

func TestRequireTokenRejectsUnknownBearerToken(t *testing.T) {
	a := newTestAPI(t)

	req := httptest.NewRequest(http.MethodGet, PrefixToken+"/status", nil)
	req.Header.Set("Authorization", "Bearer token-that-was-never-created")
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusUnauthorized)
	}
}

func TestRequireTokenRejectsMalformedAuthorizationHeader(t *testing.T) {
	a := newTestAPI(t)

	req := httptest.NewRequest(http.MethodGet, PrefixToken+"/status", nil)
	req.Header.Set("Authorization", "Basic dXNlcjpwYXNz") // well-formed, but not a bearer token
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusUnauthorized)
	}
}

func TestRequireTokenRejectsEmptyBearerToken(t *testing.T) {
	a := newTestAPI(t)

	req := httptest.NewRequest(http.MethodGet, PrefixToken+"/status", nil)
	req.Header.Set("Authorization", "Bearer ")
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusUnauthorized)
	}
}

func TestUserIDFromContextUnsetReturnsFalse(t *testing.T) {
	if _, ok := userIDFromContext(context.Background()); ok {
		t.Error("userIDFromContext() ok = true for a context carrying no value, want false")
	}
}

func TestBearerToken(t *testing.T) {
	tests := []struct {
		name   string
		header string
		want   string
		wantOK bool
	}{
		{"valid", "Bearer abc123", "abc123", true},
		{"missing prefix", "abc123", "", false},
		{"wrong scheme", "Basic abc123", "", false},
		{"empty token", "Bearer ", "", false},
		{"empty header", "", "", false},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got, ok := bearerToken(tt.header)
			if got != tt.want || ok != tt.wantOK {
				t.Errorf("bearerToken(%q) = (%q, %v), want (%q, %v)", tt.header, got, ok, tt.want, tt.wantOK)
			}
		})
	}
}
