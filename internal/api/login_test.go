package api

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"

	"github.com/strausmann/tesserarr/internal/identity"
	"github.com/strausmann/tesserarr/internal/invite"
)

// oidcIdentityProvider is the literal value identity.oidc.go stores in
// identities.provider for every OIDC login (its own oidcProviderName
// constant, unexported — this package cannot import it, only its value).
// Any test here that seeds a returning identity directly in the database,
// bypassing a first login through the whole flow, has to match it exactly.
const oidcIdentityProvider = "oidc"

// createTestUserWithOIDCIdentity inserts a user already bound to subject
// through the OIDC provider — a returning login, as opposed to the
// brand-new-subject-plus-invite path TestHandleInviteRedeem... below
// exercises.
func createTestUserWithOIDCIdentity(t *testing.T, a *API, userID, subject string) {
	t.Helper()

	if _, err := a.store.DB().Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		userID, "test user", time.Now().UTC().Format(time.RFC3339),
	); err != nil {
		t.Fatalf("insert test user: %v", err)
	}
	if _, err := a.store.DB().Exec(
		`INSERT INTO identities (user_id, provider, subject, email) VALUES (?, ?, ?, ?)`,
		userID, oidcIdentityProvider, subject, nil,
	); err != nil {
		t.Fatalf("insert test identity: %v", err)
	}
}

// findSetCookie returns the cookie named name among resp's Set-Cookie
// headers, or nil if none is there — the same inline-search idiom
// csrf_test.go already uses (see e.g. TestRequireCSRFMintsCookieOnSafeMethod),
// pulled out here only because login_test.go needs it more than once.
func findSetCookie(resp *http.Response, name string) *http.Cookie {
	for _, c := range resp.Cookies() {
		if c.Name == name {
			return c
		}
	}
	return nil
}

// TestHandleLoginOIDCRedirectsToProviderWithState is the first half of the
// brief: starting an ordinary login sends the browser to the provider with
// a non-empty, single-use state value — the thing Callback later checks
// before ever exchanging a code (see identity.ErrLoginStateInvalid).
func TestHandleLoginOIDCRedirectsToProviderWithState(t *testing.T) {
	a := newTestAPI(t)

	req := httptest.NewRequest(http.MethodGet, LoginOIDCPath, nil)
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusFound {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusFound)
	}

	loc, err := url.Parse(rec.Header().Get("Location"))
	if err != nil {
		t.Fatalf("parse Location header: %v", err)
	}
	if got := loc.Scheme + "://" + loc.Host; got != a.cfg.OIDCIssuerURL {
		t.Errorf("redirected to %q, want the configured provider %q", got, a.cfg.OIDCIssuerURL)
	}
	if loc.Path != "/authorize" {
		t.Errorf("redirect path = %q, want %q", loc.Path, "/authorize")
	}
	if loc.Query().Get("state") == "" {
		t.Error("redirect URL carries no state parameter")
	}
	if got := loc.Query().Get("client_id"); got != fakeOIDCClientID {
		t.Errorf("redirect URL client_id = %q, want %q", got, fakeOIDCClientID)
	}
}

// TestHandleLoginOIDCCallbackWithValidCodeSetsSessionCookie is the second
// half: completing a login the provider redirected back from sets a
// session cookie carrying every attribute ADR-0007 decision point 1
// requires — HttpOnly, Secure, SameSite=Strict.
func TestHandleLoginOIDCCallbackWithValidCodeSetsSessionCookie(t *testing.T) {
	a := newTestAPI(t)
	createTestUserWithOIDCIdentity(t, a, "user-1", "returning-subject")

	beginReq := httptest.NewRequest(http.MethodGet, LoginOIDCPath, nil)
	beginRec := httptest.NewRecorder()
	a.Handler().ServeHTTP(beginRec, beginReq)
	authURL := beginRec.Header().Get("Location")

	state, code := completeFakeLogin(t, authURL, "returning-subject")

	callbackReq := httptest.NewRequest(http.MethodGet,
		LoginOIDCCallbackPath+"?state="+url.QueryEscape(state)+"&code="+url.QueryEscape(code), nil)
	callbackRec := httptest.NewRecorder()
	a.Handler().ServeHTTP(callbackRec, callbackReq)

	if callbackRec.Code != http.StatusFound {
		t.Fatalf("status = %d, want %d", callbackRec.Code, http.StatusFound)
	}

	cookie := findSetCookie(callbackRec.Result(), a.cfg.SessionCookieName)
	if cookie == nil {
		t.Fatal("response carries no session cookie")
	}
	if !cookie.HttpOnly {
		t.Error("session cookie is not HttpOnly")
	}
	if !cookie.Secure {
		t.Error("session cookie is not Secure")
	}
	if cookie.SameSite != http.SameSiteStrictMode {
		t.Errorf("session cookie SameSite = %v, want %v", cookie.SameSite, http.SameSiteStrictMode)
	}

	userID, err := a.sessions.Lookup(cookie.Value)
	if err != nil {
		t.Fatalf("sessions.Lookup(cookie.Value) returned error: %v", err)
	}
	if userID != "user-1" {
		t.Errorf("session resolved to user %q, want %q", userID, "user-1")
	}
}

// TestHandleLoginOIDCCallbackAlsoMintsCSRFCookie is the fix the team lead
// flagged: without it, the CSRF cookie only ever appeared incidentally, the
// next time a safe-method request hit the session path (ensureCSRFCookie in
// csrf.go). That made the frontend's very first state-changing request
// after logging in — plausibly a logout, not necessarily a catalog fetch —
// fail once, for no reason a user could see: no CSRF cookie yet means no
// value to echo into the double-submit header. Minting it here, in the same
// response as the session cookie, removes the ordering dependency instead
// of leaving it for the UI-verification task to rediscover as a flaky
// failure.
func TestHandleLoginOIDCCallbackAlsoMintsCSRFCookie(t *testing.T) {
	a := newTestAPI(t)
	createTestUserWithOIDCIdentity(t, a, "user-1", "returning-subject")

	beginReq := httptest.NewRequest(http.MethodGet, LoginOIDCPath, nil)
	beginRec := httptest.NewRecorder()
	a.Handler().ServeHTTP(beginRec, beginReq)
	authURL := beginRec.Header().Get("Location")

	state, code := completeFakeLogin(t, authURL, "returning-subject")

	// The callback request deliberately carries no CSRF cookie of its own —
	// exactly the state a browser is in on its very first request after
	// being redirected back from the provider.
	callbackReq := httptest.NewRequest(http.MethodGet,
		LoginOIDCCallbackPath+"?state="+url.QueryEscape(state)+"&code="+url.QueryEscape(code), nil)
	callbackRec := httptest.NewRecorder()
	a.Handler().ServeHTTP(callbackRec, callbackReq)

	if callbackRec.Code != http.StatusFound {
		t.Fatalf("status = %d, want %d", callbackRec.Code, http.StatusFound)
	}

	if cookie := findSetCookie(callbackRec.Result(), a.cfg.SessionCookieName); cookie == nil {
		t.Error("response carries no session cookie")
	}

	csrf := findSetCookie(callbackRec.Result(), csrfCookieName)
	if csrf == nil {
		t.Fatal("response carries no CSRF cookie — the frontend's first state-changing request after login would fail")
	}
	if csrf.Value == "" {
		t.Error("minted CSRF cookie has an empty value")
	}
	if csrf.HttpOnly {
		t.Error("CSRF cookie is HttpOnly — the frontend must be able to read it via JavaScript (see ensureCSRFCookie in csrf.go)")
	}
}

// TestHandleLoginOIDCCallbackWithWrongStateIsRejectedWithoutCookie proves
// the callback route rejects a forged or replayed state before setting any
// cookie — and, following identity.OIDCLogin.Callback's own contract (see
// ErrLoginStateInvalid), before the code the request carried is ever spent:
// the real state and the very same code still work afterward.
func TestHandleLoginOIDCCallbackWithWrongStateIsRejectedWithoutCookie(t *testing.T) {
	a := newTestAPI(t)
	createTestUserWithOIDCIdentity(t, a, "user-1", "returning-subject")

	beginReq := httptest.NewRequest(http.MethodGet, LoginOIDCPath, nil)
	beginRec := httptest.NewRecorder()
	a.Handler().ServeHTTP(beginRec, beginReq)
	authURL := beginRec.Header().Get("Location")

	realState, code := completeFakeLogin(t, authURL, "returning-subject")

	wrongReq := httptest.NewRequest(http.MethodGet,
		LoginOIDCCallbackPath+"?state=not-the-real-state&code="+url.QueryEscape(code), nil)
	wrongRec := httptest.NewRecorder()
	a.Handler().ServeHTTP(wrongRec, wrongReq)

	if wrongRec.Code == http.StatusFound {
		t.Fatalf("status = %d, want a rejection, not a redirect", wrongRec.Code)
	}
	if cookie := findSetCookie(wrongRec.Result(), a.cfg.SessionCookieName); cookie != nil {
		t.Errorf("response carries a session cookie despite the wrong state: %v", cookie)
	}

	realReq := httptest.NewRequest(http.MethodGet,
		LoginOIDCCallbackPath+"?state="+url.QueryEscape(realState)+"&code="+url.QueryEscape(code), nil)
	realRec := httptest.NewRecorder()
	a.Handler().ServeHTTP(realRec, realReq)

	if realRec.Code != http.StatusFound {
		t.Fatalf("callback with the real state and the same code: status = %d, want %d — "+
			"the earlier wrong-state attempt must not have consumed the code",
			realRec.Code, http.StatusFound)
	}
	if cookie := findSetCookie(realRec.Result(), a.cfg.SessionCookieName); cookie == nil {
		t.Error("callback with the real state carries no session cookie")
	}
}

// TestHandleInviteRedeemRedirectsToProvider is the invited person's half of
// the brief: the path is public and starts a login exactly like
// LoginOIDCPath does, just with the token from the path instead of "".
func TestHandleInviteRedeemRedirectsToProvider(t *testing.T) {
	a := newTestAPI(t)

	token, err := invite.Create(a.store.DB(), 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}

	req := httptest.NewRequest(http.MethodGet, "/invite/"+token, nil)
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusFound {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusFound)
	}
	loc, err := url.Parse(rec.Header().Get("Location"))
	if err != nil {
		t.Fatalf("parse Location header: %v", err)
	}
	if loc.Path != "/authorize" {
		t.Errorf("redirect path = %q, want %q", loc.Path, "/authorize")
	}
}

// TestHandleInviteRedeemCarriesTheTokenThroughToSignup is the proof the
// brief specifically asks for: the invite token really travels with the
// login attempt from the redeem link all the way to Callback. Rather than
// reaching into identity's unexported login-attempt store (which this
// package cannot do anyway), it drives the whole round trip and checks the
// one externally observable fact that could only be true if the token made
// it through: the invitation is spent afterward.
func TestHandleInviteRedeemCarriesTheTokenThroughToSignup(t *testing.T) {
	a := newTestAPI(t)

	token, err := invite.Create(a.store.DB(), 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}

	redeemReq := httptest.NewRequest(http.MethodGet, "/invite/"+token, nil)
	redeemRec := httptest.NewRecorder()
	a.Handler().ServeHTTP(redeemRec, redeemReq)
	authURL := redeemRec.Header().Get("Location")

	state, code := completeFakeLogin(t, authURL, "brand-new-subject")

	callbackReq := httptest.NewRequest(http.MethodGet,
		LoginOIDCCallbackPath+"?state="+url.QueryEscape(state)+"&code="+url.QueryEscape(code), nil)
	callbackRec := httptest.NewRecorder()
	a.Handler().ServeHTTP(callbackRec, callbackReq)

	if callbackRec.Code != http.StatusFound {
		t.Fatalf("status = %d, want %d", callbackRec.Code, http.StatusFound)
	}
	cookie := findSetCookie(callbackRec.Result(), a.cfg.SessionCookieName)
	if cookie == nil {
		t.Fatal("response carries no session cookie")
	}
	userID, err := a.sessions.Lookup(cookie.Value)
	if err != nil {
		t.Fatalf("sessions.Lookup(cookie.Value) returned error: %v", err)
	}

	if err := invite.Redeem(a.store.DB(), token, userID); !errors.Is(err, invite.ErrAlreadyRedeemed) {
		t.Errorf("invite.Redeem() a second time returned %v, want invite.ErrAlreadyRedeemed — "+
			"the invite token from the redeem link never reached Callback", err)
	}
}

// TestHandleInviteRedeemRejectsEmptyToken exercises handleInviteRedeem's own
// guard directly. The real route ("GET /invite/{token}") can never actually
// deliver an empty token — net/http.ServeMux's {token} wildcard only
// matches a non-empty path segment, so a request for exactly "/invite/"
// never reaches this handler at all — but the guard is cheap, honest
// defense-in-depth against whatever future change might route here
// differently, and this is the only way to prove it works.
func TestHandleInviteRedeemRejectsEmptyToken(t *testing.T) {
	a := newTestAPI(t)

	req := httptest.NewRequest(http.MethodGet, "/invite/", nil)
	req.SetPathValue("token", "")
	rec := httptest.NewRecorder()
	a.handleInviteRedeem(rec, req)

	if rec.Code != http.StatusBadRequest {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusBadRequest)
	}
}

// TestHandleInviteRedeemRejectsOverlongToken reproduces the memory-
// exhaustion finding from the security audit
// (~/security-audit-skill/tesserarr/run-1/findings.json, "Unauthenticated
// memory-exhaustion DoS via unbounded invite-token amplification"): without
// a length cap, an unauthenticated caller can drive a near-1-MiB path
// segment (Go's own http.Server default MaxHeaderBytes is the only prior
// limit) all the way into identity.OIDCLogin.BeginLogin, which retains it
// verbatim in the in-process loginAttemptStore for a full 10-minute TTL —
// measured at ~4,100x amplification and 474 MB of real RSS growth from 300
// single-connection requests in 14.5 seconds. Real invite tokens are a
// fixed 32-byte-hex (64 characters); maxInviteTokenLen leaves generous
// headroom without opening the amplification path at all.
func TestHandleInviteRedeemRejectsOverlongToken(t *testing.T) {
	a := newTestAPI(t)

	overlong := strings.Repeat("a", maxInviteTokenLen+1)
	req := httptest.NewRequest(http.MethodGet, "/invite/"+overlong, nil)
	req.SetPathValue("token", overlong)
	rec := httptest.NewRecorder()
	a.handleInviteRedeem(rec, req)

	if rec.Code != http.StatusBadRequest {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusBadRequest)
	}
}

// TestHandleInviteRedeemAcceptsTokenAtTheLengthLimit is the boundary case
// TestHandleInviteRedeemRejectsOverlongToken's ">" needs alongside it: a
// real 64-character token — well within maxInviteTokenLen — must still
// redirect normally. Exercised through the real route (not called
// directly), so it also proves ServeMux's {token} wildcard still delivers
// an ordinary invite token to the handler with the length check in place.
func TestHandleInviteRedeemAcceptsTokenAtTheLengthLimit(t *testing.T) {
	a := newTestAPI(t)

	token, err := invite.Create(a.store.DB(), 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}
	if len(token) > maxInviteTokenLen {
		t.Fatalf("test fixture assumption broken: invite.Create() token is %d bytes, want <= %d", len(token), maxInviteTokenLen)
	}

	req := httptest.NewRequest(http.MethodGet, "/invite/"+token, nil)
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusFound {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusFound)
	}
}

// TestHandleLogoutInvalidatesSessionAndClearsCookie is the brief's last
// case: logging out ends the session server-side AND tells the browser to
// drop the cookie, through the real route (session check, then CSRF check
// — logout is a state-changing POST like any other on the session path).
func TestHandleLogoutInvalidatesSessionAndClearsCookie(t *testing.T) {
	a := newTestAPI(t)
	token := createTestUserAndSession(t, a, "user-1")

	req := httptest.NewRequest(http.MethodPost, LogoutPath, nil)
	req.AddCookie(&http.Cookie{Name: a.cfg.SessionCookieName, Value: token})   //nolint:gosec // G124: see the comment on the AddCookie call in TestRequireCSRFRejectsMissingHeader (csrf_test.go)
	req.AddCookie(&http.Cookie{Name: csrfCookieName, Value: "matching-token"}) //nolint:gosec // G124: same as above
	req.Header.Set(csrfHeaderName, "matching-token")
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusNoContent {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusNoContent)
	}

	if _, err := a.sessions.Lookup(token); !errors.Is(err, identity.ErrSessionInvalid) {
		t.Errorf("session still resolves after logout (err=%v), want identity.ErrSessionInvalid", err)
	}

	cookie := findSetCookie(rec.Result(), a.cfg.SessionCookieName)
	if cookie == nil {
		t.Fatal("response carries no cookie to clear the session")
	}
	if cookie.MaxAge >= 0 {
		t.Errorf("cleared cookie MaxAge = %d, want negative (delete immediately)", cookie.MaxAge)
	}
}

// TestHandleLogoutWithoutSessionCookieIsIdempotent calls handleLogout
// directly — through the real route, requireSession would already reject a
// request with no cookie before this handler ever ran, so this is the only
// way to prove the handler itself stays idempotent, matching
// SessionStore.Destroy's own idempotent contract.
func TestHandleLogoutWithoutSessionCookieIsIdempotent(t *testing.T) {
	a := newTestAPI(t)

	req := httptest.NewRequest(http.MethodPost, LogoutPath, nil)
	rec := httptest.NewRecorder()
	a.handleLogout(rec, req)

	if rec.Code != http.StatusNoContent {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusNoContent)
	}
}

// TestHandleLogoutReturnsServerErrorWhenDestroyFails closes the store out
// from under a valid session token — the same technique
// TestHealthEndpointReturnsServiceUnavailableWhenDatabaseIsUnreachable uses
// — isolating the network-error path around SessionStore.Destroy from the
// happy path above. Called directly, bypassing requireSession, which would
// itself fail against the same closed store first.
func TestHandleLogoutReturnsServerErrorWhenDestroyFails(t *testing.T) {
	a := newTestAPI(t)
	token := createTestUserAndSession(t, a, "user-1")
	if err := a.store.Close(); err != nil {
		t.Fatalf("store.Close() returned error: %v", err)
	}

	req := httptest.NewRequest(http.MethodPost, LogoutPath, nil)
	req.AddCookie(&http.Cookie{Name: a.cfg.SessionCookieName, Value: token}) //nolint:gosec // G124: see the comment on the AddCookie call in TestRequireCSRFRejectsMissingHeader (csrf_test.go)
	rec := httptest.NewRecorder()
	a.handleLogout(rec, req)

	if rec.Code != http.StatusInternalServerError {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusInternalServerError)
	}
}
