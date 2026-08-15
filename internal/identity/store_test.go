package identity

import (
	"strconv"
	"testing"
	"time"
)

func TestLoginAttemptStorePutAndTake(t *testing.T) {
	s := newLoginAttemptStore()
	s.put("state-1", loginAttempt{verifier: "v1", inviteToken: "inv-1"}, time.Hour)

	got, ok := s.take("state-1")
	if !ok {
		t.Fatal("take() reported not found for a freshly put attempt")
	}
	if got.verifier != "v1" {
		t.Errorf("take() verifier = %q, want %q", got.verifier, "v1")
	}
	if got.inviteToken != "inv-1" {
		t.Errorf("take() inviteToken = %q, want %q", got.inviteToken, "inv-1")
	}
}

// TestLoginAttemptStoreTakeIsSingleUse confirms a state value authenticates
// exactly one callback — the security property ErrLoginStateInvalid exists
// to enforce.
func TestLoginAttemptStoreTakeIsSingleUse(t *testing.T) {
	s := newLoginAttemptStore()
	s.put("state-1", loginAttempt{verifier: "v1"}, time.Hour)

	if _, ok := s.take("state-1"); !ok {
		t.Fatal("first take() reported not found, want found")
	}
	if _, ok := s.take("state-1"); ok {
		t.Fatal("second take() for the same state reported found — a consumed state must not authenticate a second callback")
	}
}

func TestLoginAttemptStoreTakeRejectsUnknownState(t *testing.T) {
	s := newLoginAttemptStore()
	if _, ok := s.take("never-put"); ok {
		t.Fatal("take() reported found for a state that was never put")
	}
}

func TestLoginAttemptStoreTakeRejectsExpiredAttempt(t *testing.T) {
	s := newLoginAttemptStore()
	current := time.Now()
	s.now = func() time.Time { return current }

	s.put("state-1", loginAttempt{verifier: "v1"}, time.Minute)
	current = current.Add(2 * time.Minute)

	if _, ok := s.take("state-1"); ok {
		t.Fatal("take() reported found for an attempt past its TTL")
	}
}

// TestLoginAttemptStorePutSweepsExpiredAttempts exercises put's
// opportunistic eviction: an attempt nobody ever completed must not sit in
// the map forever just because it was never take()n.
func TestLoginAttemptStorePutSweepsExpiredAttempts(t *testing.T) {
	s := newLoginAttemptStore()
	current := time.Now()
	s.now = func() time.Time { return current }

	s.put("abandoned", loginAttempt{verifier: "v-abandoned"}, time.Minute)
	current = current.Add(2 * time.Minute) // "abandoned" is now expired, but still in the map

	s.put("fresh", loginAttempt{verifier: "v-fresh"}, time.Minute)

	s.mu.Lock()
	_, stillThere := s.byState["abandoned"]
	count := len(s.byState)
	s.mu.Unlock()

	if stillThere {
		t.Error("put() did not sweep the expired \"abandoned\" attempt out of the map")
	}
	if count != 1 {
		t.Errorf("map has %d entries after the sweep, want 1 (only \"fresh\")", count)
	}
}

// TestLoginAttemptStorePutReplacesStateCollision documents what happens on
// a state collision — not expected in practice (see randomURLSafe's
// entropy) but the second put winning cleanly, rather than the two
// attempts merging or the first silently staying, is the safer of the two
// possible behaviors.
func TestLoginAttemptStorePutReplacesStateCollision(t *testing.T) {
	s := newLoginAttemptStore()
	s.put("state-1", loginAttempt{verifier: "first"}, time.Hour)
	s.put("state-1", loginAttempt{verifier: "second"}, time.Hour)

	got, ok := s.take("state-1")
	if !ok {
		t.Fatal("take() reported not found")
	}
	if got.verifier != "second" {
		t.Errorf("take() verifier = %q, want %q (the second put should win)", got.verifier, "second")
	}
}

// TestLoginAttemptStorePutEnforcesCapacity is the regression test for the
// unbounded half of the audit's quadratic-growth finding: BeginLogin (and
// therefore put) is reachable with no session and no credential at all
// (see router.go's MiddlewarePublic on LoginOIDCPath), so nothing upstream
// of loginAttemptStore stops an attacker from calling it in a tight loop.
// Overriding maxAttempts (rather than pushing loginAttemptTTL-many real
// entries) keeps this test fast while exercising the exact same code path
// production hits at the real cap.
func TestLoginAttemptStorePutEnforcesCapacity(t *testing.T) {
	s := newLoginAttemptStore()
	s.maxAttempts = 3

	for i := 0; i < 5; i++ {
		s.put(stateFor(i), loginAttempt{verifier: verifierFor(i)}, time.Hour)
	}

	s.mu.Lock()
	count := len(s.byState)
	s.mu.Unlock()
	if count > 3 {
		t.Fatalf("map has %d entries after 5 puts against a capacity of 3, want at most 3", count)
	}

	// The two oldest attempts (put first, so — under a constant TTL — due
	// to expire first) must be the ones a capacity eviction sacrifices, not
	// an arbitrary or newest one: a newly arriving legitimate login must
	// never be the one silently dropped to make room for itself.
	if _, ok := s.take(stateFor(0)); ok {
		t.Error("take() found state 0, want it evicted to enforce the capacity cap")
	}
	if _, ok := s.take(stateFor(1)); ok {
		t.Error("take() found state 1, want it evicted to enforce the capacity cap")
	}
	for i := 2; i < 5; i++ {
		if _, ok := s.take(stateFor(i)); !ok {
			t.Errorf("take() did not find state %d, want it still present (within the capacity cap)", i)
		}
	}
}

// TestLoginAttemptStorePutAmortizedCostDoesNotGrowWithSize is the
// regression test for the quadratic half of the audit's finding: put used
// to scan the entire map on every call to sweep expired entries, so its
// own cost grew linearly with however many attempts already sat in the
// store — turning a flood of BeginLogin calls (see router.go's
// MiddlewarePublic on LoginOIDCPath: no session, no credential needed)
// into an O(n^2) bill paid by every subsequent, entirely legitimate login
// attempt.
//
// This measures rather than asserts a specific complexity class (timing
// assertions with a fixed threshold are flaky across machines) — it
// compares the average per-call cost of a batch of puts once the store
// already holds a large population against a batch run against a nearly
// empty store, and requires the two to be within the same order of
// magnitude. Against the pre-fix full-map-scan implementation this fails
// outright (populated-store puts run orders of magnitude slower); against
// the fix it holds because put's cost is now bounded by maxAttempts, not
// by how many attempts happen to be pending.
func TestLoginAttemptStorePutAmortizedCostDoesNotGrowWithSize(t *testing.T) {
	const population = 20_000
	const sampleBatch = 500

	baseline := newLoginAttemptStore()
	baselineElapsed := timePuts(baseline, "baseline", sampleBatch)

	populated := newLoginAttemptStore()
	for i := 0; i < population; i++ {
		populated.put(stateFor(i), loginAttempt{verifier: verifierFor(i)}, time.Hour)
	}
	populatedElapsed := timePuts(populated, "populated", sampleBatch)

	// A generous multiplier: amortized O(1)/O(log n) work naturally has
	// some noise, but it must not scale with population the way an O(n)
	// per-call scan does — at population/sampleBatch = 40x the store size,
	// a real full-map scan reliably blows past even a 10x margin.
	if populatedElapsed > baselineElapsed*10 {
		t.Errorf(
			"put() against a store with %d entries took %v for %d calls; against a near-empty store the same batch took %v — "+
				"more than 10x slower suggests put's cost is scaling with the store's size again",
			population, populatedElapsed, sampleBatch, baselineElapsed,
		)
	}
}

func timePuts(s *loginAttemptStore, label string, n int) time.Duration {
	start := time.Now()
	for i := 0; i < n; i++ {
		s.put(label+"-"+stateFor(i), loginAttempt{verifier: label + "-" + verifierFor(i)}, time.Hour)
	}
	return time.Since(start)
}

func stateFor(i int) string    { return "state-" + strconv.Itoa(i) }
func verifierFor(i int) string { return "verifier-" + strconv.Itoa(i) }
