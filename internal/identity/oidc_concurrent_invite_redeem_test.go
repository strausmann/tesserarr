package identity

import (
	"context"
	"errors"
	"fmt"
	"sync"
	"testing"

	"github.com/strausmann/tesserarr/internal/invite"
)

// TestConcurrentCreateUserFromInviteExactlyOneSucceeds is a counter-review
// addition prompted by the security-audit fix "redeem the invite inside the
// same transaction as the account" (invite.Redeem now runs inside
// createUserFromInvite's own tx, before commit, instead of as a separate,
// non-transactional call cleaned up afterward — see oidc.go's own comment
// on createUserFromInvite for the finding this closes).
//
// WHAT IT ACTUALLY COVERS, AND WHAT IT DOES NOT — read this before trusting
// it as a regression guard: TestRedeemConcurrentAttemptsExactlyOneSucceeds
// (internal/invite/invite_test.go) only exercises Redeem's atomic UPDATE as
// a short, standalone autocommit statement against a *sql.DB — never the
// path production code actually takes now, where Redeem runs INSIDE a
// longer, three-statement transaction (two prior INSERTs, then Redeem, then
// commit). This test closes that specific gap: it drives twelve goroutines,
// twelve distinct OIDC subjects, all racing to redeem the SAME invite token
// through the real createUserFromInvite, reached through the real Callback,
// against a real file-backed database with production's own pragmas (WAL,
// busy_timeout(5000), foreign_keys(1)) — and confirms exactly one succeeds
// even with the transaction held open for the whole three-statement
// sequence rather than one short statement.
//
// WHAT IT DOES NOT DO — verified, not assumed: this test does NOT serve as
// a regression guard for the security finding itself. Run against the
// pre-fix code (Redeem called separately, after commit, against o.db, with
// a best-effort compensating DELETE on failure) it STAYS GREEN, because
// plain concurrent load never exercises the actual defect — the bug only
// manifests when the compensating DELETE fails to run at all (a crash) or
// fails outright (a blocked write), neither of which occurs in an ordinary
// concurrent race with no failure injected. TestOIDCLoginCreateUserFromInviteRollsBackWhenRedeemFails
// is the test that actually catches the pre-fix defect, via a blocking
// trigger simulating exactly that failure — this test is a genuine,
// additional confirmation that the transaction-wrapping fix didn't
// introduce a NEW concurrency problem, not a substitute for that one.
func TestConcurrentCreateUserFromInviteExactlyOneSucceeds(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, 0)
	login := newTestOIDCLogin(t, provider, db, sessions)

	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}

	const attempts = 12
	type prepped struct {
		state, code string
	}
	preps := make([]prepped, attempts)
	for i := 0; i < attempts; i++ {
		authURL := login.BeginLogin(token)
		state, code := completeLogin(t, authURL, subjectFor(i), "")
		preps[i] = prepped{state: state, code: code}
	}

	results := make([]error, attempts)
	userIDs := make([]string, attempts)
	start := make(chan struct{})
	var wg sync.WaitGroup
	for i := 0; i < attempts; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			<-start
			userID, err := login.Callback(context.Background(), preps[i].state, preps[i].code, "")
			results[i] = err
			userIDs[i] = userID
		}(i)
	}
	close(start)
	wg.Wait()

	successes := 0
	for i, err := range results {
		if err == nil {
			successes++
			if userIDs[i] == "" {
				t.Errorf("attempt %d: succeeded but returned an empty session token", i)
			}
			continue
		}
		if !errors.Is(err, ErrUnknownIdentityWithoutInvite) {
			t.Errorf("attempt %d: unexpected error (want ErrUnknownIdentityWithoutInvite or nil): %v", i, err)
		}
	}
	if successes != 1 {
		t.Errorf("successes = %d, want exactly 1 — the invite-gate concurrency guarantee does not hold "+
			"for the real createUserFromInvite transaction (as opposed to the standalone Redeem() call)", successes)
	}

	var userCount int
	if err := db.QueryRow(`SELECT COUNT(*) FROM users`).Scan(&userCount); err != nil {
		t.Fatalf("count users: %v", err)
	}
	if userCount != 1 {
		t.Errorf("users table has %d row(s) after the race, want exactly 1", userCount)
	}
}

func subjectFor(i int) string {
	return fmt.Sprintf("concurrent-invite-subject-%02d", i)
}
