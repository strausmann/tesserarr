package identity

import (
	"context"
	"database/sql"
	"sync"
	"testing"
	"time"

	"github.com/strausmann/tesserarr/internal/invite"
)

// TestOIDCLoginSurvivesLosingCreateRaceEvenWithoutARealRace is the OIDC
// counterpart of TestAuthenticateSurvivesLosingCreateRaceEvenWithoutARealRace
// (forwardauth_concurrent_test.go) — the actual regression guard, not the
// goroutine test below it. It exercises createUserFromInvite's PRIMARY KEY
// fallback deterministically instead of relying on goroutine scheduling: it
// plants the winning identity row itself, between where resolveUser's own
// lookupIdentity would have seen a miss and createUserFromInvite's own
// INSERT, so the INSERT is guaranteed to hit the PRIMARY KEY violation
// every run — no flakiness, no dependency on how the Go scheduler happens
// to interleave concurrent Callback calls.
//
// WHAT THIS PROVES ABOUT PRE-FIX BEHAVIOR — verified, not assumed: run
// against createUserFromInvite as it stood before this test was added (no
// *sqlite.Error/SQLITE_CONSTRAINT_PRIMARYKEY check, no resolveExistingIdentity
// fallback), this test FAILS at its very first assertion — createUserFromInvite
// returns the raw wrapped driver error ("identity: bind oidc identity: ...
// constraint failed: UNIQUE constraint failed: identities.provider,
// identities.subject") instead of the planted winner's id. This is the
// opposite of the two goroutine-based tests in this package
// (TestConcurrentCreateUserFromInviteExactlyOneSucceeds, and this file's own
// TestConcurrentOIDCCallbackSameSubjectExactlyOneAccountCreated below) —
// their own doc comments explain why ordinary concurrent load never
// reliably forces the actual PRIMARY KEY collision this fix targets. This
// test forces it on every single run, which is what makes it the real
// regression guard rather than a nice-to-have addition.
func TestOIDCLoginSurvivesLosingCreateRaceEvenWithoutARealRace(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	login := newTestOIDCLogin(t, provider, db, sessions)

	const subject = "already-bound-oidc-subject"
	winnerID := newUserID()
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		winnerID, "already here", formatTime(time.Now()),
	); err != nil {
		t.Fatalf("plant winning user: %v", err)
	}
	if _, err := db.Exec(
		`INSERT INTO identities (user_id, provider, subject) VALUES (?, ?, ?)`,
		winnerID, oidcProviderName, subject,
	); err != nil {
		t.Fatalf("plant winning identity: %v", err)
	}

	// A loser has to reach createUserFromInvite in the first place, which
	// only happens when resolveUser's own lookupIdentity still reports
	// sql.ErrNoRows — exactly what this test cannot arrange without racing
	// a goroutine against the planted row above, since lookupIdentity would
	// otherwise find it immediately and never call createUserFromInvite at
	// all. This test therefore calls createUserFromInvite directly, the
	// same way a losing goroutine's own call would, rather than going
	// through Callback's resolveUser gate — mirroring exactly what
	// TestAuthenticateSurvivesLosingCreateRaceEvenWithoutARealRace does for
	// ForwardAuthLogin.createUserFromInvite.
	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}
	userID, err := login.createUserFromInvite(subject, "loser@example.org", EmailVerificationUnknown, token)
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
	// invite.Redeem at all — the INSERT into identities that triggers the
	// PRIMARY KEY fallback happens BEFORE createUserFromInvite's own call to
	// invite.Redeem, so the loser's transaction rolls back before that point
	// is ever reached (see createUserFromInvite's own doc comment on the
	// fix). It fell back to the ordinary known-identity path instead, which
	// never touches invitations — checked here, not assumed, the same way
	// the forward-auth sibling test checks it for its own fix.
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

// TestOIDCLoginLosingCreateRaceRefreshesEmailLikeReturningLogin is the
// email-refresh counterpart of TestOIDCLoginSurvivesLosingCreateRaceEvenWithoutARealRace
// above: it proves the race LOSER's fallback doesn't just resolve to the
// winner's account (that part is already covered), it also applies the
// SAME email refresh an ordinary returning login gets from resolveUser's
// "case err == nil" branch (see TestResolveUserUpdatesEmailWhenProviderClaimChanges,
// oidc_test.go) — resolveExistingIdentity's own doc comment states the
// loser must look exactly like a returning login, and that now includes
// the email resolveUser itself would have refreshed had this call simply
// found the identity already bound instead of racing into it.
//
// This is deliberately not folded into the existing race test above:
// asserting only `err == nil` and `userID == winnerID` there would pass
// just as well against a fix that resolved the account but silently
// dropped the email claim on the floor — exactly what the pre-merge
// updateRole (role only, no email parameter at all) would have done had
// it still compiled. Only reading back users.email actually distinguishes
// "resolved to the right account" from "resolved to the right account AND
// treated it like a real returning login".
func TestOIDCLoginLosingCreateRaceRefreshesEmailLikeReturningLogin(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	login := newTestOIDCLogin(t, provider, db, sessions)

	const subject = "already-bound-oidc-subject-email-refresh"
	winnerID := newUserID()
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at, email) VALUES (?, ?, ?, ?)`,
		winnerID, "already here", formatTime(time.Now()), "stale@example.org",
	); err != nil {
		t.Fatalf("plant winning user: %v", err)
	}
	if _, err := db.Exec(
		`INSERT INTO identities (user_id, provider, subject) VALUES (?, ?, ?)`,
		winnerID, oidcProviderName, subject,
	); err != nil {
		t.Fatalf("plant winning identity: %v", err)
	}

	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}
	// The loser's own id token carried a DIFFERENT (fresher) email claim
	// than whatever the winner's own login stored — the same shape
	// TestResolveUserUpdatesEmailWhenProviderClaimChanges exercises for an
	// ordinary returning login, just reached through the race-loser path
	// instead.
	userID, err := login.createUserFromInvite(subject, "fresh@example.org", EmailVerificationUnknown, token)
	if err != nil {
		t.Fatalf("createUserFromInvite() returned error: %v, want it to fall back to the winner's account", err)
	}
	if userID != winnerID {
		t.Fatalf("createUserFromInvite() userID = %q, want the planted winner %q", userID, winnerID)
	}

	got := readUserEmail(t, db, winnerID)
	if !got.Valid || got.String != "fresh@example.org" {
		t.Errorf("email after losing the create race = %+v, want fresh@example.org (refreshed like any returning login)", got)
	}
}

// TestOIDCLoginLosingCreateRaceKeepsEmailWhenClaimIsEmpty is
// TestOIDCLoginLosingCreateRaceRefreshesEmailLikeReturningLogin's CASE WHEN
// counterpart, mirroring TestResolveUserKeepsEmailWhenClaimIsEmptyOnReturningLogin
// for the loser path: an empty email claim must not blank out whatever the
// winner's own login already stored. Both directions of the guard need
// their own test the same way resolveUser's already do — a fix that always
// overwrote unconditionally would pass the sibling test above and only
// fail here.
func TestOIDCLoginLosingCreateRaceKeepsEmailWhenClaimIsEmpty(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	login := newTestOIDCLogin(t, provider, db, sessions)

	const subject = "already-bound-oidc-subject-email-keep"
	winnerID := newUserID()
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at, email) VALUES (?, ?, ?, ?)`,
		winnerID, "already here", formatTime(time.Now()), "keep-me@example.org",
	); err != nil {
		t.Fatalf("plant winning user: %v", err)
	}
	if _, err := db.Exec(
		`INSERT INTO identities (user_id, provider, subject) VALUES (?, ?, ?)`,
		winnerID, oidcProviderName, subject,
	); err != nil {
		t.Fatalf("plant winning identity: %v", err)
	}

	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}
	// The loser's own claim carries NO email this time — the guard's whole
	// point (see updateProfile's own comment): this must not clear the
	// address the winner's login already stored.
	userID, err := login.createUserFromInvite(subject, "", EmailVerificationUnknown, token)
	if err != nil {
		t.Fatalf("createUserFromInvite() returned error: %v, want it to fall back to the winner's account", err)
	}
	if userID != winnerID {
		t.Fatalf("createUserFromInvite() userID = %q, want the planted winner %q", userID, winnerID)
	}

	got := readUserEmail(t, db, winnerID)
	if !got.Valid || got.String != "keep-me@example.org" {
		t.Errorf("email after losing the create race with an empty claim = %+v, want keep-me@example.org (kept, not cleared)", got)
	}
}

// TestConcurrentOIDCCallbackSameSubjectExactlyOneAccountCreated drives real
// goroutines at the real Callback, the same shape
// TestConcurrentAuthenticateSameSubjectExactlyOneAccountCreated
// (forwardauth_concurrent_test.go) uses for forward-auth's identical fix,
// and the same shape TestConcurrentCreateUserFromInviteExactlyOneSucceeds
// (oidc_concurrent_invite_redeem_test.go) uses for the UNRELATED
// invite-uniqueness race — this test is the one that uses the SAME OIDC
// subject across every attempt, which that one deliberately does not
// (subjectFor(i) gives each goroutine there its own distinct subject).
//
// WHAT IT ACTUALLY COVERS, AND WHAT IT DOES NOT — read this before trusting
// it as the regression guard for the fix itself. Both siblings above
// (TestConcurrentAuthenticateSameSubjectExactlyOneAccountCreated for
// forward-auth, TestConcurrentCreateUserFromInviteExactlyOneSucceeds for
// OIDC's own, unrelated invite-uniqueness race) document their own analogous
// goroutine tests as STAYING GREEN against pre-fix code under ordinary
// scheduling — this one does NOT share that property, measured rather than
// assumed: run 10 times against createUserFromInvite as it stood before the
// PRIMARY KEY fallback was added, this test FAILED 8 of 10 runs (the raw
// wrapped driver error surfacing on at least one goroutine each time) and
// PASSED the other 2. That makes it a usually-but-not-reliably effective
// regression indicator on its own for THIS particular race — unlike its two
// siblings, which this repository's own resolveExistingIdentity-based fixes
// apparently make harder for ordinary Go scheduling to race past cleanly.
// TestOIDCLoginSurvivesLosingCreateRaceEvenWithoutARealRace above is still
// what this repository relies on: it forces the collision deterministically
// and catches the pre-fix defect on every single run, which this test
// cannot promise on its own.
//
// This test's value is the same different, genuine one its sibling states:
// confirming the fix does not introduce a NEW problem under real
// concurrent load — that whichever interleaving actually happens, exactly
// one account and one redeemed invitation come out the other side, never
// two, and never a session that resolves nowhere.
//
// WHY resolving through sessions.Lookup, not comparing tokens directly:
// Callback's return value is a SESSION TOKEN, not a user id — asserting on
// it directly would prove nothing about which account either request
// landed in, only that some session exists. This resolves every returned
// token back to a user id via sessions.Lookup and asserts THAT set has
// exactly one member — the only assertion that actually distinguishes "all
// requests share the one real account" from "each interleaving silently
// created its own", the exact trap a test that only checks "err == nil"
// falls into.
func TestConcurrentOIDCCallbackSameSubjectExactlyOneAccountCreated(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	login := newTestOIDCLogin(t, provider, db, sessions)

	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}

	const subject = "concurrent-oidc-subject"
	const attempts = 8

	// Authorization codes are single-use (fakeAuthCode.used, oidc_test.go),
	// so every attempt needs its OWN BeginLogin/completeLogin round trip
	// prepared up front — the SAME subject each time — before any goroutine
	// starts, exactly the same shape
	// TestConcurrentCreateUserFromInviteExactlyOneSucceeds already uses for
	// its own, differently-subjected attempts.
	type prepped struct{ state, code string }
	preps := make([]prepped, attempts)
	for i := 0; i < attempts; i++ {
		authURL := login.BeginLogin(token)
		state, code := completeLogin(t, authURL, subject, "")
		preps[i] = prepped{state: state, code: code}
	}

	results := make([]string, attempts)
	errs := make([]error, attempts)
	start := make(chan struct{})
	var wg sync.WaitGroup
	for i := 0; i < attempts; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			<-start
			sessionToken, err := login.Callback(context.Background(), preps[i].state, preps[i].code, "")
			results[i] = sessionToken
			errs[i] = err
		}(i)
	}
	close(start)
	wg.Wait()

	// The fix's own point: NO attempt sees the raw constraint-violation
	// error — every one of them resolves to a normal login (the winner via
	// the ordinary create path, everyone else via the same known-identity
	// path an entirely unrelated returning login would take).
	for i, err := range errs {
		if err != nil {
			t.Errorf("attempt %d: Callback() returned error: %v", i, err)
		}
		if results[i] == "" {
			t.Errorf("attempt %d: Callback() returned an empty session token", i)
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
		`SELECT COUNT(*) FROM identities WHERE provider = ? AND subject = ?`, oidcProviderName, subject,
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
