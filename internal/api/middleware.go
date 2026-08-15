package api

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"strings"
)

// maxRequestBodyBytes bounds every JSON request body this package decodes
// (handleCreateToken in tokens.go) before json.NewDecoder(r.Body).Decode
// ever runs — that decode call must consume an entire JSON string token
// before it can hand it back, so a per-field length check performed only
// AFTER Decode (maxTokenLabelLen) buys nothing against an oversized value:
// the whole body already sits in memory by the time either check runs.
// Measured without this guard: a 512 MiB request body inflated the server
// process by roughly 2 GiB (a ~4x amplification) and succeeded outright —
// an authenticated caller at the LOWEST trust level (any invited user with
// a live session) could drive that arbitrarily high, enough to OOM-kill a
// container with a memory limit.
//
// Applied via http.MaxBytesReader(w, r.Body, maxRequestBodyBytes) at each
// decode site rather than once centrally: this package's two path spaces
// deliberately share no single request-entry funnel (see the package
// comment on router.go) for either of its middleware chains, so there is no
// one place upstream of both handlers to hang this on without inventing one.
//
// 4 KiB is generous headroom, not a tight fit — createTokenRequest needs a
// label of at most maxTokenLabelLen (100) bytes plus a small integer, and
// that fits many times over inside this limit with plenty of room for
// ordinary JSON formatting.
const maxRequestBodyBytes = 4096

// errUnexpectedTrailingData is decodeJSONBody's sentinel for "the body held
// a complete, valid JSON value, and then something else" — a second JSON
// value glued to the first, or non-whitespace debris after it. Nothing
// outside decodeJSONBody inspects this value; the one call site
// (tokens.go) folds it into the same generic "invalid request body"
// response it already gives a malformed decode, so this exists only to
// give the second Decode call below one recognizable name instead of an
// anonymous fmt.Errorf.
var errUnexpectedTrailingData = errors.New("unexpected data after JSON value")

// decodeJSONBody decodes exactly one JSON value from r.Body into dst and
// then requires the remainder of the body to be empty or whitespace-only —
// callers pass an r.Body already wrapped in http.MaxBytesReader(w, r.Body,
// maxRequestBodyBytes) (see that constant's own comment) so both checks
// below run against the same bounded reader.
//
// json.Decoder.Decode returns as soon as it has read the FIRST complete
// JSON value; it neither errors nor looks at whatever follows. A caller who
// sends {"label":"sonarr"} followed by, say, 200 KiB of padding — or a
// second JSON value — sails through a single Decode call exactly as if
// nothing else were there: the padding sits unread in the body, and the
// per-field length check the handler runs afterwards (maxTokenLabelLen)
// never sees it either, because it only looks at the field Decode already
// populated. That defeats maxRequestBodyBytes's own purpose in spirit even
// though the single Decode call itself never exceeds the byte limit: the
// guard exists to bound what a request can make this handler process, and
// "whatever a caller can fit after a tiny valid prefix" is not bounded by a
// limit that only ever gets asked about the first value.
//
// The second Decode call is the fix encoding/json itself documents for
// this ("extra data" — see json.Decoder): it resumes reading exactly where
// the first call stopped, skipping insignificant whitespace (so a trailing
// newline from curl or an editor stays accepted, per the caller's own
// choice — see tokens.go for where this function is invoked), and either
// reaches EOF (nothing more — valid) or does not (reject).
// Because it reads from the same underlying, MaxBytesReader-wrapped body
// as the first call, any attempt to use a large padding run to search for
// that EOF is itself bounded by maxRequestBodyBytes — this second read
// cannot become the unbounded read the first one was already guarded
// against.
func decodeJSONBody(r *http.Request, dst any) error {
	dec := json.NewDecoder(r.Body)
	if err := dec.Decode(dst); err != nil {
		return err
	}
	if err := dec.Decode(new(json.RawMessage)); err != io.EOF {
		if err == nil {
			return errUnexpectedTrailingData
		}
		return err
	}
	return nil
}

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
// It resolves the token through a.apiTokens (internal/apitoken.Store), a
// store entirely separate from the identity.SessionStore requireSession
// resolves cookies through. That separation is not only which check runs —
// it is which STORAGE the check runs against, and both matter: the
// shortcut this package exists to rule out is a single helper that accepts
// "cookie OR header" in one code path (there is exactly one place that reads
// a cookie and exactly one that reads a header, and they stay that way), but
// a shared store underneath two separately-coded checks would have left a
// second, quieter version of the same problem — a session token happening to
// double as a bearer token because both checks, however cleanly separated in
// code, ultimately asked the same table the same question. See
// internal/apitoken's package comment for why this is treated as the second,
// independent barrier the concept requires, not merely a naming difference.
func (a *API) requireToken(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		token, ok := bearerToken(r.Header.Get("Authorization"))
		if !ok {
			http.Error(w, "token required", http.StatusUnauthorized)
			return
		}

		userID, err := a.apiTokens.Lookup(token)
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
