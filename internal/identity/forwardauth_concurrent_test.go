package identity

import (
	"database/sql"
	"sync"
	"testing"
	"time"

	"github.com/strausmann/tesserarr/internal/invite"
)

// TestConcurrentAuthenticateSameSubjectExactlyOneAccountCreated drives real
// goroutines at the real Authenticate, the same shape
// TestConcurrentCreateUserFromInviteExactlyOneSucceeds (the OIDC sibling in
// oidc_concurrent_invite_redeem_test.go) uses for the invite-uniqueness
// race.
//
// WHAT IT ACTUALLY COVERS, AND WHAT IT DOES NOT — read this before trusting
// it as the regression guard for the fix itself, the same caveat the OIDC
// sibling states about its own test: run against the PRE-FIX code (the
// loser's transaction rolling back into a raw wrapped driver error instead
// of falling back to the known-user path), this test STAYS GREEN — verified,
// not assumed. Every attempt here calls lookupIdentity as its own bare,
// un-transacted SELECT before createUserFromInvite ever starts a
// transaction, and Go's connection-pool scheduling combined with SQLite's
// single-writer WAL mode reliably interleaves these 8 goroutines such that
// each one's SELECT already sees whichever attempt won earlier — the actual
// PRIMARY KEY collision this fix targets never fires under this load
// pattern. TestAuthenticateSurvivesLosingCreateRaceEvenWithoutARealRace
// below is the test that actually forces the collision (by planting the
// winning row directly, deterministically) and catches the pre-fix defect.
//
// This test's value is a different, genuine one: confirming the fix does
// not introduce a NEW problem under real concurrent load — that whichever
// interleaving actually happens, exactly one account and one redeemed
// invitation come out the other side, never two, and never a session that
// resolves nowhere.
//
// WHY resolving through sessions.Lookup, not comparing tokens directly:
// Authenticate's return value is a SESSION TOKEN, not a user id — asserting
// on it directly would prove nothing about which account either request
// landed in, only that some session exists. This resolves every returned
// token back to a user id via sessions.Lookup and asserts THAT set has
// exactly one member — the only assertion that actually distinguishes "all
// requests share the one real account" from "each interleaving silently
// created its own".
func TestConcurrentAuthenticateSameSubjectExactlyOneAccountCreated(t *testing.T) {
	sessions, db := newTestSessionStore(t, time.Hour)
	login := NewForwardAuthLogin(db, sessions, nil, "")

	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}

	const subject = "concurrent-forward-auth-subject"
	const attempts = 8
	claims := ForwardAuthClaims{Subject: subject, Username: "friend"}

	results := make([]string, attempts)
	errs := make([]error, attempts)
	start := make(chan struct{})
	var wg sync.WaitGroup
	for i := 0; i < attempts; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			<-start
			sessionToken, err := login.Authenticate(claims, token, "")
			results[i] = sessionToken
			errs[i] = err
		}(i)
	}
	close(start)
	wg.Wait()

	// The fix's own point: NO attempt sees the raw constraint-violation
	// error — every one of them resolves to a normal login (the winner via
	// the ordinary create path, everyone else via the same "known user"
	// path an entirely unrelated returning login would take).
	for i, err := range errs {
		if err != nil {
			t.Errorf("attempt %d: Authenticate() returned error: %v", i, err)
		}
		if results[i] == "" {
			t.Errorf("attempt %d: Authenticate() returned an empty session token", i)
		}
	}
	if t.Failed() {
		return
	}

	// Resolve every returned token back to the account it actually
	// authenticated — the assertion that distinguishes "one real account"
	// from "the fix let each request mint its own".
	userIDs := make(map[string]struct{}, attempts)
	for i, tok := range results {
		userID, err := sessions.Lookup(tok)
		if err != nil {
			t.Fatalf("attempt %d: sessions.Lookup() returned error: %v", i, err)
		}
		userIDs[userID] = struct{}{}
	}
	if len(userIDs) != 1 {
		t.Errorf("attempts resolved to %d distinct user ids, want exactly 1 (all %d attempts sharing the one account for %q)",
			len(userIDs), attempts, subject)
	}

	var userCount int
	if err := db.QueryRow(`SELECT COUNT(*) FROM users`).Scan(&userCount); err != nil {
		t.Fatalf("count users: %v", err)
	}
	if userCount != 1 {
		t.Errorf("users table has %d row(s) after the race, want exactly 1", userCount)
	}

	var identityCount int
	if err := db.QueryRow(
		`SELECT COUNT(*) FROM identities WHERE provider = ? AND subject = ?`, forwardAuthProviderName, subject,
	).Scan(&identityCount); err != nil {
		t.Fatalf("count identities: %v", err)
	}
	if identityCount != 1 {
		t.Errorf("identities table has %d row(s) for %q, want exactly 1", identityCount, subject)
	}

	// The invite is redeemed exactly once — by whichever attempt actually
	// won the create race, not left unredeemed or double-redeemed.
	var redeemedBy string
	if err := db.QueryRow(
		`SELECT redeemed_by FROM invitations WHERE token_hash = ?`, hashInviteTokenForTest(token),
	).Scan(&redeemedBy); err != nil {
		t.Fatalf("read invitation: %v", err)
	}
	if _, ok := userIDs[redeemedBy]; !ok {
		t.Errorf("invitation redeemed_by = %q, want the one account every attempt resolved to", redeemedBy)
	}
}

// TestAuthenticateSurvivesLosingCreateRaceEvenWithoutARealRace exercises
// the same code path deterministically instead of relying on goroutine
// scheduling: it plants the winning identity row itself, between
// lookupIdentity's first (miss) and createUserFromInvite's own INSERT, so
// the INSERT is guaranteed to hit the PRIMARY KEY violation every run —
// no flakiness, no dependency on how the Go scheduler happens to interleave
// the goroutines above.
func TestAuthenticateSurvivesLosingCreateRaceEvenWithoutARealRace(t *testing.T) {
	sessions, db := newTestSessionStore(t, time.Hour)
	login := NewForwardAuthLogin(db, sessions, nil, "")

	const subject = "already-bound-subject"
	winnerID := newUserID()
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		winnerID, "already here", formatTime(time.Now()),
	); err != nil {
		t.Fatalf("plant winning user: %v", err)
	}
	if _, err := db.Exec(
		`INSERT INTO identities (user_id, provider, subject) VALUES (?, ?, ?)`,
		winnerID, forwardAuthProviderName, subject,
	); err != nil {
		t.Fatalf("plant winning identity: %v", err)
	}

	// A loser has to reach createUserFromInvite in the first place, which
	// only happens when lookupIdentity itself still reports sql.ErrNoRows
	// — exactly what this test cannot arrange without racing a goroutine
	// against the planted row above, since lookupIdentity would otherwise
	// find it immediately and never call createUserFromInvite at all. This
	// test therefore calls createUserFromInvite directly, the same way a
	// losing goroutine's own call would, rather than going through
	// Authenticate's lookupIdentity gate.
	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}
	userID, err := login.createUserFromInvite(ForwardAuthClaims{Subject: subject, Username: "friend"}, token)
	if err != nil {
		t.Fatalf("createUserFromInvite() returned error: %v, want it to fall back to the winner's account", err)
	}
	if userID != winnerID {
		t.Errorf("createUserFromInvite() userID = %q, want the planted winner %q", userID, winnerID)
	}

	var userCount int
	if err := db.QueryRow(`SELECT COUNT(*) FROM users`).Scan(&userCount); err != nil {
		t.Fatalf("count users: %v", err)
	}
	if userCount != 1 {
		t.Errorf("users table has %d row(s), want exactly 1 (no second account created)", userCount)
	}

	// The invite must NOT be consumed by the loser: it never reached
	// invite.Redeem at all (see createUserFromInvite's own doc comment on
	// the fix) — it fell back to the ordinary known-user path instead,
	// which never touches invitations.
	var redeemedBy sql.NullString
	if err := db.QueryRow(
		`SELECT redeemed_by FROM invitations WHERE token_hash = ?`, hashInviteTokenForTest(token),
	).Scan(&redeemedBy); err != nil {
		t.Fatalf("read invitation: %v", err)
	}
	if redeemedBy.Valid {
		t.Errorf("invitation redeemed_by = %q, want NULL (the loser must not consume it)", redeemedBy.String)
	}
}
