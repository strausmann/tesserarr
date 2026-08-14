package api

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

// csrfDummyHandler records whether it was invoked, so a test can tell whether
// requireCSRF let the request through or stopped it before ever reaching the
// wrapped handler.
func csrfDummyHandler(called *bool) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		*called = true
		w.WriteHeader(http.StatusOK)
	}
}

func TestRequireCSRFRejectsMissingHeader(t *testing.T) {
	a := newTestAPI(t)
	called := false

	req := httptest.NewRequest(http.MethodPost, PrefixApp+"/whatever", nil)
	req.AddCookie(&http.Cookie{Name: csrfCookieName, Value: "some-token-value"}) //nolint:gosec // G124: this is an INCOMING request cookie built for a test client — AddCookie only ever serializes Name/Value onto the Cookie header, so Secure/HttpOnly/SameSite (response-cookie attributes) do not apply here
	rec := httptest.NewRecorder()
	a.requireCSRF(csrfDummyHandler(&called)).ServeHTTP(rec, req)

	if rec.Code != http.StatusForbidden {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusForbidden)
	}
	if called {
		t.Error("handler was called despite a missing CSRF header")
	}
}

func TestRequireCSRFRejectsWrongHeader(t *testing.T) {
	a := newTestAPI(t)
	called := false

	req := httptest.NewRequest(http.MethodPost, PrefixApp+"/whatever", nil)
	req.AddCookie(&http.Cookie{Name: csrfCookieName, Value: "the-real-token"}) //nolint:gosec // G124: see the comment on the AddCookie call in TestRequireCSRFRejectsMissingHeader
	req.Header.Set(csrfHeaderName, "a-different-token")
	rec := httptest.NewRecorder()
	a.requireCSRF(csrfDummyHandler(&called)).ServeHTTP(rec, req)

	if rec.Code != http.StatusForbidden {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusForbidden)
	}
	if called {
		t.Error("handler was called despite a mismatched CSRF header")
	}
}

// TestRequireCSRFRejectsMissingCookie covers the other half of "there is
// nothing to compare against": a header with no cookie at all must fail
// exactly like a cookie with no header, not be treated as trivially correct.
func TestRequireCSRFRejectsMissingCookie(t *testing.T) {
	a := newTestAPI(t)
	called := false

	req := httptest.NewRequest(http.MethodPost, PrefixApp+"/whatever", nil)
	req.Header.Set(csrfHeaderName, "whatever-the-client-sends")
	rec := httptest.NewRecorder()
	a.requireCSRF(csrfDummyHandler(&called)).ServeHTTP(rec, req)

	if rec.Code != http.StatusForbidden {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusForbidden)
	}
	if called {
		t.Error("handler was called despite no CSRF cookie ever being set")
	}
}

func TestRequireCSRFAcceptsMatchingHeader(t *testing.T) {
	a := newTestAPI(t)
	called := false

	req := httptest.NewRequest(http.MethodPost, PrefixApp+"/whatever", nil)
	req.AddCookie(&http.Cookie{Name: csrfCookieName, Value: "the-real-token"}) //nolint:gosec // G124: see the comment on the AddCookie call in TestRequireCSRFRejectsMissingHeader
	req.Header.Set(csrfHeaderName, "the-real-token")
	rec := httptest.NewRecorder()
	a.requireCSRF(csrfDummyHandler(&called)).ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusOK)
	}
	if !called {
		t.Error("handler was not called despite a matching CSRF header")
	}
}

// TestRequireCSRFSkipsSafeMethod is the decision this task's brief calls out
// explicitly: GET, HEAD, OPTIONS and TRACE must never be blocked by this
// check. A safe method (RFC 9110) must not itself change state, so there is
// nothing here for a forged request to achieve — blocking it anyway would
// only break an ordinary bookmarked, prefetched or middle-click-opened link
// into the session space, for no security benefit.
func TestRequireCSRFSkipsSafeMethod(t *testing.T) {
	a := newTestAPI(t)
	called := false

	req := httptest.NewRequest(http.MethodGet, PrefixApp+"/whatever", nil)
	rec := httptest.NewRecorder()
	a.requireCSRF(csrfDummyHandler(&called)).ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusOK)
	}
	if !called {
		t.Error("handler was not called for a safe GET request with no CSRF cookie or header at all")
	}
}

// TestRequireCSRFMintsCookieOnSafeMethod is the flip side of skipping the
// check on GET: a browser has to acquire the double-submit cookie from
// somewhere before it can ever echo it back on the POST that follows, and
// this is the only place that hands one out.
func TestRequireCSRFMintsCookieOnSafeMethod(t *testing.T) {
	a := newTestAPI(t)
	called := false

	req := httptest.NewRequest(http.MethodGet, PrefixApp+"/whatever", nil)
	rec := httptest.NewRecorder()
	a.requireCSRF(csrfDummyHandler(&called)).ServeHTTP(rec, req)

	var minted *http.Cookie
	for _, c := range rec.Result().Cookies() {
		if c.Name == csrfCookieName {
			minted = c
		}
	}
	if minted == nil {
		t.Fatal("response carries no Set-Cookie for the CSRF cookie")
	}
	if minted.Value == "" {
		t.Error("minted CSRF cookie has an empty value")
	}
	if minted.HttpOnly {
		t.Error("CSRF cookie is HttpOnly — the frontend must be able to read it via JavaScript")
	}
}

// TestRequireCSRFDoesNotReplaceExistingCookie proves ensureCSRFCookie is
// idempotent: a request that already carries a CSRF cookie must not get a
// fresh one minted underneath it on every safe request, which would
// invalidate any header value a page had already cached from the first one.
func TestRequireCSRFDoesNotReplaceExistingCookie(t *testing.T) {
	a := newTestAPI(t)
	called := false

	req := httptest.NewRequest(http.MethodGet, PrefixApp+"/whatever", nil)
	req.AddCookie(&http.Cookie{Name: csrfCookieName, Value: "already-issued-token"}) //nolint:gosec // G124: see the comment on the AddCookie call in TestRequireCSRFRejectsMissingHeader
	rec := httptest.NewRecorder()
	a.requireCSRF(csrfDummyHandler(&called)).ServeHTTP(rec, req)

	for _, c := range rec.Result().Cookies() {
		if c.Name == csrfCookieName {
			t.Fatalf("response minted a new CSRF cookie (%q) even though the request already carried one", c.Value)
		}
	}
}

// TestSessionPathStillRequiresCSRFOnUnsafeMethodDespiteValidSession is the
// fallstrick this task calls out by name: a valid session alone must not be
// enough for a state-changing request on the session path. It goes through
// a.wrap — the exact function router.go uses to build every registered
// session-space route — rather than calling requireCSRF directly, so it
// proves the production wiring, not just the middleware in isolation.
func TestSessionPathStillRequiresCSRFOnUnsafeMethodDespiteValidSession(t *testing.T) {
	a := newTestAPI(t)
	token := createTestUserAndSession(t, a, "user-1")
	called := false

	req := httptest.NewRequest(http.MethodPost, PrefixApp+"/whatever", nil)
	req.AddCookie(&http.Cookie{Name: a.cfg.SessionCookieName, Value: token}) //nolint:gosec // G124: see the comment on the AddCookie call in TestRequireCSRFRejectsMissingHeader
	rec := httptest.NewRecorder()
	a.wrap(MiddlewareSession, csrfDummyHandler(&called)).ServeHTTP(rec, req)

	if rec.Code != http.StatusForbidden {
		t.Errorf("status = %d, want %d (a valid session must not substitute for the CSRF check on a state-changing request)",
			rec.Code, http.StatusForbidden)
	}
	if called {
		t.Error("handler was called for a state-changing request with a valid session but no CSRF header")
	}
}

// TestSessionPathAcceptsUnsafeMethodWithMatchingCSRF is the positive
// counterpart: a valid session together with a correctly echoed CSRF header
// does reach the handler, through the same production wiring as the test
// above.
func TestSessionPathAcceptsUnsafeMethodWithMatchingCSRF(t *testing.T) {
	a := newTestAPI(t)
	token := createTestUserAndSession(t, a, "user-1")
	called := false

	req := httptest.NewRequest(http.MethodPost, PrefixApp+"/whatever", nil)
	req.AddCookie(&http.Cookie{Name: a.cfg.SessionCookieName, Value: token})   //nolint:gosec // G124: see the comment on the AddCookie call in TestRequireCSRFRejectsMissingHeader
	req.AddCookie(&http.Cookie{Name: csrfCookieName, Value: "matching-token"}) //nolint:gosec // G124: see the comment on the AddCookie call in TestRequireCSRFRejectsMissingHeader
	req.Header.Set(csrfHeaderName, "matching-token")
	rec := httptest.NewRecorder()
	a.wrap(MiddlewareSession, csrfDummyHandler(&called)).ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusOK)
	}
	if !called {
		t.Error("handler was not called for a state-changing request with a valid session and matching CSRF header")
	}
}

// TestTokenPathAcceptsUnsafeMethodWithoutCSRFHeader is the actual proof
// ADR-0007 and this task's brief both ask for: the token path carries no
// forgery check at all, not even for a state-changing request, because it is
// immune by construction (see the package comment on router.go and
// requireCSRF's own comment in csrf.go). It runs through a.Handler() against
// the real, already-registered token-space route — not a synthetic one — with
// a POST, an Authorization header, and NEITHER a CSRF cookie NOR a CSRF
// header anywhere in the request. If this test needed either of those to
// pass, the separation this whole package is built around would already be
// broken.
func TestTokenPathAcceptsUnsafeMethodWithoutCSRFHeader(t *testing.T) {
	a := newTestAPI(t)
	token := createTestUserAndSession(t, a, "user-1")

	req := httptest.NewRequest(http.MethodPost, PrefixToken+"/status", nil)
	req.Header.Set("Authorization", "Bearer "+token)
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Errorf("status = %d, want %d (the token path must accept a state-changing request without any CSRF cookie or header)",
			rec.Code, http.StatusOK)
	}
}

func TestGenerateCSRFTokenIsRandom(t *testing.T) {
	tok1 := generateCSRFToken()
	tok2 := generateCSRFToken()
	if tok1 == "" || tok2 == "" {
		t.Fatal("generateCSRFToken() returned an empty value")
	}
	if tok1 == tok2 {
		t.Error("two consecutive calls to generateCSRFToken() returned the same value")
	}
}

func TestConstantTimeEqual(t *testing.T) {
	tests := []struct {
		name string
		a, b string
		want bool
	}{
		{"equal", "same-value", "same-value", true},
		{"different content, same length", "aaaaaaaa", "bbbbbbbb", false},
		{"different length", "short", "much-longer-value", false},
		{"both empty", "", "", true},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := constantTimeEqual(tt.a, tt.b); got != tt.want {
				t.Errorf("constantTimeEqual(%q, %q) = %v, want %v", tt.a, tt.b, got, tt.want)
			}
		})
	}
}
