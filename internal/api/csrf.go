package api

import (
	"crypto/rand"
	"crypto/subtle"
	"encoding/base64"
	"net/http"
)

// csrfCookieName is the double-submit cookie's name. Unlike the session
// cookie (see cfg.SessionCookieName), it carries no secret of its own — its
// only job is to prove the request came from a page that can read this
// domain's cookies, which a forged cross-site request cannot do. That makes
// it deployment-independent, unlike the session cookie name: there is
// nothing here for an operator to need to override, so it is not a config
// field, only a package constant.
const csrfCookieName = "tesserarr_csrf"

// csrfHeaderName is the header the frontend must echo the cookie's value
// into for the check in requireCSRF to pass. http.Header canonicalizes
// lookups, so the exact casing used here does not matter to Get/Set — it is
// written the conventional way for readability only.
const csrfHeaderName = "X-CSRF-Token"

// csrfTokenBytes matches sessionTokenBytes in internal/identity/session.go.
// There is no reason for this token to carry less entropy than a session
// token: a successful forgery against an authenticated session is exactly as
// damaging as a stolen one.
const csrfTokenBytes = 32

// csrfSafeMethods are the methods requireCSRF never blocks, no matter what
// the cookie and header say. RFC 9110 defines GET, HEAD, OPTIONS and TRACE as
// safe: a conforming handler must not let them change state, so a forged one
// has nothing to achieve, and checking anyway would only break an ordinary
// bookmarked, prefetched or middle-click-opened link into the session space
// for no security benefit. requireCSRF still mints the cookie on these
// methods when the request does not already carry one (see
// ensureCSRFCookie) — that is how a browser ever acquires a value to echo
// back on the POST, PUT, PATCH or DELETE that follows.
var csrfSafeMethods = map[string]bool{
	http.MethodGet:     true,
	http.MethodHead:    true,
	http.MethodOptions: true,
	http.MethodTrace:   true,
}

// requireCSRF is the double-submit forgery check for the session path. wrap()
// in router.go chains it AFTER requireSession, so a request with no valid
// session cookie has already been rejected with 401 by the time this
// middleware ever runs — a forgery attempt can never tell from the response
// which of the two checks stopped it.
//
// This middleware is wired only into the MiddlewareSession chain; it never
// runs in front of a PrefixToken route. See the package comment on router.go
// and ADR-0007 (docs/adr/0007-anmeldung-sitzung-oberflaeche-token-fremdsysteme-getrennte-pfade.md)
// for the full reasoning behind that split. In short: cross-site request
// forgery is structurally a cookie problem. A browser attaches cookies to a
// request automatically, regardless of which site's page triggered it —
// that automatic attachment is the entire attack this check defends against.
// An Authorization header is never sent automatically, and a foreign page
// cannot add one to a cross-origin request without a CORS preflight this
// server does not grant. The token path is therefore immune BY
// CONSTRUCTION, not by a check that has to be written, tested and kept in
// sync here. Bolting this same double-submit check onto the token path "to
// be safe" would add no protection — it would only require Prowlarr, Sonarr
// and Radarr to somehow discover and echo a cookie they never receive in the
// first place, breaking every one of them for nothing.
func (a *API) requireCSRF(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if csrfSafeMethods[r.Method] {
			ensureCSRFCookie(w, r)
			next.ServeHTTP(w, r)
			return
		}

		cookie, err := r.Cookie(csrfCookieName)
		if err != nil || cookie.Value == "" {
			http.Error(w, "request could not be verified", http.StatusForbidden)
			return
		}

		header := r.Header.Get(csrfHeaderName)
		if header == "" || !constantTimeEqual(cookie.Value, header) {
			http.Error(w, "request could not be verified", http.StatusForbidden)
			return
		}

		next.ServeHTTP(w, r)
	})
}

// ensureCSRFCookie mints and sets a fresh double-submit cookie whenever the
// request does not already carry a usable one. It runs on every safe-method
// request (see requireCSRF), which is what lets a browser acquire the cookie
// well before it ever needs to send the header back: the session-status
// check right after login, or any other GET, hands one out.
//
// A cookie that is already present and non-empty is left alone. Re-minting
// it on every safe request would not make the double-submit check any
// stronger — it would only invalidate whatever value a page had already read
// via JavaScript and cached for its next state-changing request, turning an
// otherwise-valid submission into a spurious mismatch.
func ensureCSRFCookie(w http.ResponseWriter, r *http.Request) {
	if cookie, err := r.Cookie(csrfCookieName); err == nil && cookie.Value != "" {
		return
	}

	http.SetCookie(w, &http.Cookie{ //nolint:gosec // G124: HttpOnly is deliberately left at its false default — the double-submit pattern only works if the frontend's own JavaScript can read this value and echo it into csrfHeaderName on the next state-changing request. A page on a different origin could not read it either way: the same-origin policy the pattern relies on already blocks that, regardless of this flag. Secure and SameSite ARE set, just not HttpOnly.
		Name:     csrfCookieName,
		Value:    generateCSRFToken(),
		Path:     "/",
		Secure:   true,
		SameSite: http.SameSiteStrictMode,
	})
}

// generateCSRFToken draws csrfTokenBytes of randomness from crypto/rand. See
// the equivalent comment on generateToken in internal/identity/session.go
// for why its error return is intentionally ignored rather than handled: as
// of Go 1.24, crypto/rand.Read is documented never to return one.
func generateCSRFToken() string {
	buf := make([]byte, csrfTokenBytes)
	_, _ = rand.Read(buf)
	return base64.RawURLEncoding.EncodeToString(buf)
}

// constantTimeEqual compares the cookie and header values without leaking
// their contents through a timing side channel. Unlike the session token
// comparison in internal/identity/session.go — which matches a hash by exact
// lookup in a database query, never comparing two secrets side by side in
// application code — this IS exactly the kind of direct, attacker-influenced
// byte comparison a naive == or early-exit loop can leak timing information
// about: both the cookie and the header on an unsafe request are supplied by
// whoever sent the request. crypto/subtle.ConstantTimeCompare exists
// precisely for this comparison, including its documented, non-secret-length
// short-circuit when the two slices differ in length.
func constantTimeEqual(a, b string) bool {
	return subtle.ConstantTimeCompare([]byte(a), []byte(b)) == 1
}
