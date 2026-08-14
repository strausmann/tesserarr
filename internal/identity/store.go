package identity

import (
	"sync"
	"time"
)

// loginAttemptTTL bounds how long an OIDC login attempt may sit unfinished
// between BeginLogin and Callback before it is treated as expired. A few
// minutes comfortably covers a human choosing an identity provider account
// and typing a password, without leaving a forgeable window open for long.
const loginAttemptTTL = 10 * time.Minute

// loginAttempt is everything a single OIDC login round trip needs to
// survive between the redirect to the provider and the callback.
type loginAttempt struct {
	// verifier is the PKCE code verifier BeginLogin generated for this
	// attempt. It is never sent to the browser in any form the browser
	// could read back and replay — only its S256 challenge goes into the
	// authorization URL; the verifier itself lives only here.
	verifier string
	// inviteToken is the invitation this login is meant to redeem, or ""
	// for an ordinary login by someone who already has an identity on
	// file. It travels with the attempt rather than as a separate cookie
	// or query parameter Callback would have to trust on its own, because
	// ADR-0004 requires the invitation to be checked at the moment
	// identity is resolved — after the provider has confirmed who this
	// is, not before.
	inviteToken string
	expiresAt   time.Time
}

// loginAttemptStore holds in-flight OIDC login attempts, keyed by the state
// value BeginLogin minted for them.
//
// This is deliberately in-process memory — not a cookie, and not a table in
// internal/store's database.
//
// Not a cookie: state and the PKCE verifier exist so Callback can prove a
// request actually belongs to a login this server started. Handing either
// of them back to the browser to echo would defeat that purpose entirely —
// a forged callback could simply echo whatever a genuine one would have
// carried.
//
// Not a database table: a login attempt lives seconds to a handful of
// minutes. The cost of not surviving a process restart mid-login is that
// the person clicks the login link again — that does not warrant a
// migration or schema for state that is never meant to outlive the request
// that wrote it.
type loginAttemptStore struct {
	mu      sync.Mutex
	byState map[string]loginAttempt
	// now is indirected so tests can advance time past loginAttemptTTL
	// without an actual wait — see oidc_test.go.
	now func() time.Time
}

func newLoginAttemptStore() *loginAttemptStore {
	return &loginAttemptStore{
		byState: make(map[string]loginAttempt),
		now:     time.Now,
	}
}

// put records a as the pending attempt for state, computing its expiry from
// ttl. Any prior attempt already stored under state is silently replaced —
// state collisions are not expected (see randomURLSafe's entropy), but a
// second put cleanly winning is safer than two attempts sharing one key.
//
// Every put also opportunistically sweeps attempts nobody ever completed,
// so an abandoned login flow does not sit in memory forever. Doing this
// here — once per new attempt — is enough to keep the map from growing
// unbounded without needing a background goroutine for what is, at
// homelab scale, always a handful of entries at most.
func (s *loginAttemptStore) put(state string, a loginAttempt, ttl time.Duration) {
	s.mu.Lock()
	defer s.mu.Unlock()

	now := s.now()
	for st, existing := range s.byState {
		if now.After(existing.expiresAt) {
			delete(s.byState, st)
		}
	}

	a.expiresAt = now.Add(ttl)
	s.byState[state] = a
}

// take removes and returns the attempt stored under state. A state value
// authenticates exactly one callback: once take has returned an attempt for
// it (successfully or not), a second call with the same state finds
// nothing.
//
// Whether the lookup fails because the state was never issued, was already
// consumed by an earlier callback, or has expired, the caller gets the same
// "not found" outcome (ok == false) — see oidc.go's ErrLoginStateInvalid,
// which deliberately does not distinguish these for the same reason
// ErrSessionInvalid does not distinguish "unknown" from "expired"
// (session.go): a forged or replayed callback probing state values should
// not learn which guesses came closer.
func (s *loginAttemptStore) take(state string) (loginAttempt, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()

	a, ok := s.byState[state]
	delete(s.byState, state)
	if !ok {
		return loginAttempt{}, false
	}
	if s.now().After(a.expiresAt) {
		return loginAttempt{}, false
	}
	return a, true
}
