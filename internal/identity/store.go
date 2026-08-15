package identity

import (
	"container/list"
	"sync"
	"time"
)

// loginAttemptTTL bounds how long an OIDC login attempt may sit unfinished
// between BeginLogin and Callback before it is treated as expired. A few
// minutes comfortably covers a human choosing an identity provider account
// and typing a password, without leaving a forgeable window open for long.
const loginAttemptTTL = 10 * time.Minute

// maxLoginAttempts bounds how many login attempts loginAttemptStore holds
// at once. BeginLogin — and therefore put — needs no session and no
// credential (see router.go's MiddlewarePublic on LoginOIDCPath), so
// nothing upstream of this store stops a flood of requests from growing it
// without limit; put's own doc comment already describes real usage as
// "always a handful of entries at most", so 10,000 is generous headroom
// for that, not a tuned production ceiling.
//
// When the store is at capacity, put evicts the single oldest pending
// attempt to make room for the new one, rather than rejecting the new
// arrival: BeginLogin has no error return to report a rejection through
// (see oidc.go), and threading one through would ripple into every
// caller (see login.go) for what is, per the package comment on
// loginAttemptStore, an already-accepted failure mode — an attempt that
// does not survive to its callback just means the person clicks the login
// link again, the same cost put's own doc comment already accepts for a
// process restart. The oldest attempt is also, under the single constant
// TTL every real caller uses, the one closest to expiring on its own
// anyway, so evicting it early costs the least.
const maxLoginAttempts = 10_000

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
// pendingAttempt is what loginAttemptStore.order queues per attempt — just
// enough to find and validate the matching byState entry later, without
// order itself having to hold (and duplicate) the attempt's payload.
type pendingAttempt struct {
	state     string
	expiresAt time.Time
}

type loginAttemptStore struct {
	mu      sync.Mutex
	byState map[string]loginAttempt
	// order lists every attempt this store has ever put, oldest first,
	// regardless of whether its byState entry is still live — see
	// evictExpiredLocked and evictOldestLocked for why a node can outlive
	// its map entry, and why that is fine. Every real caller uses the same
	// loginAttemptTTL for every put, so insertion order and expiry order
	// coincide: the front of order is always the next attempt due to
	// expire, and put's opportunistic sweep (evictExpiredLocked) uses that
	// to reclaim exactly the expired attempts in time proportional to how
	// many of THOSE there are — never proportional to the store's total
	// size, which is what turned every put into an O(n) full-map scan
	// before (see this store's changelog / the security-audit finding
	// this fixes).
	order *list.List
	// now is indirected so tests can advance time past loginAttemptTTL
	// without an actual wait — see oidc_test.go.
	now func() time.Time
	// maxAttempts is the capacity cap — see maxLoginAttempts' own comment
	// for the tradeoff it makes. A struct field, not just the constant,
	// so tests can shrink it and exercise the real eviction code path
	// without pushing loginAttemptTTL-many real entries through it.
	maxAttempts int
}

func newLoginAttemptStore() *loginAttemptStore {
	return &loginAttemptStore{
		byState:     make(map[string]loginAttempt),
		order:       list.New(),
		now:         time.Now,
		maxAttempts: maxLoginAttempts,
	}
}

// put records a as the pending attempt for state, computing its expiry from
// ttl. Any prior attempt already stored under state is silently replaced —
// state collisions are not expected (see randomURLSafe's entropy), but a
// second put cleanly winning is safer than two attempts sharing one key.
//
// Every put also opportunistically sweeps attempts nobody ever completed,
// so an abandoned login flow does not sit in memory forever, and — once the
// store is at capacity — evicts the single oldest attempt to enforce
// maxAttempts (see maxLoginAttempts' own comment for that tradeoff). Doing
// both here, once per new attempt, is enough to keep the map bounded
// without needing a background goroutine.
func (s *loginAttemptStore) put(state string, a loginAttempt, ttl time.Duration) {
	s.mu.Lock()
	defer s.mu.Unlock()

	now := s.now()
	s.evictExpiredLocked(now)

	if _, exists := s.byState[state]; !exists {
		for len(s.byState) >= s.maxAttempts {
			s.evictOldestLocked()
		}
	}

	a.expiresAt = now.Add(ttl)
	s.byState[state] = a
	s.order.PushBack(pendingAttempt{state: state, expiresAt: a.expiresAt})
}

// evictExpiredLocked drops every attempt at the front of s.order whose
// expiry is in the past, freeing the map entries an abandoned login flow
// would otherwise leave behind forever. It stops at the first
// not-yet-expired node rather than scanning the rest of the queue — see
// order's own comment for why that is safe under the single constant TTL
// every real caller uses, and why it is harmless even when it is not: a
// front that is not yet expired ending the sweep early only means some
// memory is reclaimed a little later than it could be, never that take()
// mis-reports an actually-expired attempt as valid — take() independently
// re-checks expiresAt on every lookup (see its own comment) regardless of
// whether this sweep ever ran. The caller must hold s.mu.
func (s *loginAttemptStore) evictExpiredLocked(now time.Time) {
	for {
		front := s.order.Front()
		if front == nil {
			return
		}
		// order only ever holds pendingAttempt values, pushed by put alone.
		pending := front.Value.(pendingAttempt)
		if !now.After(pending.expiresAt) {
			return
		}
		s.order.Remove(front)
		s.deleteIfCurrentLocked(pending)
	}
}

// evictOldestLocked drops the single longest-pending attempt regardless of
// whether it has actually expired yet, to keep the store's size under
// maxAttempts — see maxLoginAttempts' own comment for the tradeoff this
// makes. The caller must hold s.mu.
func (s *loginAttemptStore) evictOldestLocked() {
	front := s.order.Front()
	if front == nil {
		return
	}
	s.order.Remove(front)
	s.deleteIfCurrentLocked(front.Value.(pendingAttempt)) // see evictExpiredLocked's comment
}

// deleteIfCurrentLocked removes pending's state from byState, but only if
// the map still holds exactly the entry pending was queued for. An order
// node can survive its map entry two ways — take() already consumed it, or
// a state collision (see put's own comment) replaced it with a fresher
// attempt under the same key — and in either case deleting byState[state]
// here would wrongly discard something this node no longer represents.
// Comparing expiresAt tells the two apart: a fresh put always computes a
// new one. The caller must hold s.mu.
func (s *loginAttemptStore) deleteIfCurrentLocked(pending pendingAttempt) {
	if current, ok := s.byState[pending.state]; ok && current.expiresAt.Equal(pending.expiresAt) {
		delete(s.byState, pending.state)
	}
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
