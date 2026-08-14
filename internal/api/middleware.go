package api

import (
	"context"
	"net/http"
	"strings"
)

// contextKey is a private type for the value requireSession and requireToken
// place in the request context. Using an unexported struct type, rather than a
// string or int, means this key can never collide with one some other package
// chooses for its own purposes.
type contextKey struct{ name string }

var userIDContextKey = &contextKey{name: "userID"}

// userIDFromContext returns the id of the user whose credential the running
// middleware verified, and whether one was actually set. It is unset on
// every MiddlewarePublic path (the health path among them) — wrap() never
// wraps those in requireSession or requireToken, so nothing ever populates
// this value for them.
func userIDFromContext(ctx context.Context) (string, bool) {
	id, ok := ctx.Value(userIDContextKey).(string)
	return id, ok
}

// requireSession is the session-only check. It looks at exactly one place —
// the cookie named cfg.SessionCookieName — and rejects the request the moment
// that cookie is missing or does not resolve to a live session. It never
// reads the Authorization header, not as a fallback, not defensively, not
// "just in case a future change needs it": see the package comment on
// router.go and ADR-0007 for why a check that would accept either credential
// kind is exactly the failure this package is built to rule out.
func (a *API) requireSession(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		cookie, err := r.Cookie(a.cfg.SessionCookieName)
		if err != nil {
			http.Error(w, "session required", http.StatusUnauthorized)
			return
		}

		userID, err := a.sessions.Lookup(cookie.Value)
		if err != nil {
			http.Error(w, "session required", http.StatusUnauthorized)
			return
		}

		ctx := context.WithValue(r.Context(), userIDContextKey, userID)
		next.ServeHTTP(w, r.WithContext(ctx))
	})
}

// requireToken is the token-only check. It looks at exactly one place — the
// Authorization header — and never reads the session cookie, not even to
// glance at it: see the package comment on router.go and ADR-0007.
//
// It resolves the token through the very same identity.SessionStore
// requireSession uses. That is a shared STORE, not a shared CHECK: each
// middleware still decides, entirely on its own, which credential to look
// for and where to find it, and neither ever inspects the other's source.
// The shortcut this package exists to rule out is a single helper that
// accepts "cookie OR header" in one code path — that never exists here.
// There is exactly one place that reads a cookie (requireSession) and
// exactly one that reads a header (requireToken); calling the same,
// already-tested lookup afterwards does not merge them back together. A
// dedicated, longer-lived personal API token — the kind ADR-0007 describes
// for Prowlarr, Sonarr and Radarr — is tracked separately; until it lands, a
// session token doubles as a bearer token when it is sent this way.
func (a *API) requireToken(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		token, ok := bearerToken(r.Header.Get("Authorization"))
		if !ok {
			http.Error(w, "token required", http.StatusUnauthorized)
			return
		}

		userID, err := a.sessions.Lookup(token)
		if err != nil {
			http.Error(w, "token required", http.StatusUnauthorized)
			return
		}

		ctx := context.WithValue(r.Context(), userIDContextKey, userID)
		next.ServeHTTP(w, r.WithContext(ctx))
	})
}

// bearerToken extracts the token from an "Authorization: Bearer <token>"
// header value, and reports false for anything else — a missing header, a
// different scheme (e.g. "Basic"), or a bearer scheme with nothing after it.
// It is used only by requireToken; requireSession has no header to parse, so
// there is nothing here for it to share.
func bearerToken(header string) (string, bool) {
	const prefix = "Bearer "
	if !strings.HasPrefix(header, prefix) {
		return "", false
	}
	token := strings.TrimPrefix(header, prefix)
	if token == "" {
		return "", false
	}
	return token, true
}
