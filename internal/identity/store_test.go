package identity

import (
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
