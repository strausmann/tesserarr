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
// foreign key requires, then mints a valid session token for it. The same
// token is used two ways in the tests below: as a cookie value on the session
// path, and as a bearer value on the token path — see the comment on
// requireToken in middleware.go for why that is safe and not the "cookie OR
// header" shortcut this package exists to rule out.
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
// two rejection tests above: sent the way each path expects, the very same
// token authenticates both — proving the previous two failures are about the
// credential's PLACEMENT, not about the token being invalid.
func TestEachPathAcceptsOnlyItsOwnCredential(t *testing.T) {
	a := newTestAPI(t)
	token := createTestUserAndSession(t, a, "user-1")

	t.Run("session path accepts the cookie", func(t *testing.T) {
		req := httptest.NewRequest(http.MethodGet, PrefixApp+"/session", nil)
		req.AddCookie(&http.Cookie{Name: a.cfg.SessionCookieName, Value: token}) //nolint:gosec // G124: this is an INCOMING request cookie built for a test client — AddCookie only ever serializes Name/Value onto the Cookie header, so Secure/HttpOnly/SameSite (response-cookie attributes) do not apply here
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

	t.Run("token path accepts the header", func(t *testing.T) {
		req := httptest.NewRequest(http.MethodGet, PrefixToken+"/status", nil)
		req.Header.Set("Authorization", "Bearer "+token)
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
