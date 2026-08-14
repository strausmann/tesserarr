package identity

import (
	"database/sql"
	"errors"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/strausmann/tesserarr/internal/store"
)

// newTestSessionStore opens a fully migrated database and returns both the
// SessionStore under test and the raw *sql.DB, so tests that need to read
// the sessions table directly (TestSessionTokenIsNotStoredInClear), plant a
// row Create() itself could never produce (TestSessionLookupFailsOnMalformedExpiry),
// or pull the handle out from under the store (the closed-database tests)
// can do so without re-implementing Open's migration dance.
func newTestSessionStore(t *testing.T, ttl time.Duration) (*SessionStore, *sql.DB) {
	t.Helper()
	st, err := store.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("store.Open() returned error: %v", err)
	}
	t.Cleanup(func() { _ = st.Close() })

	db := st.DB()
	return NewSessionStore(db, SessionConfig{TTL: ttl}), db
}

// createTestUser inserts the minimal row the sessions table's foreign key on
// user_id requires.
func createTestUser(t *testing.T, db *sql.DB, id string) {
	t.Helper()
	_, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		id, "test user", formatTime(time.Now()),
	)
	if err != nil {
		t.Fatalf("insert test user: %v", err)
	}
}

func TestSessionCreateAndLookup(t *testing.T) {
	s, db := newTestSessionStore(t, time.Hour)
	createTestUser(t, db, "user-1")

	token, err := s.Create("user-1")
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}
	if token == "" {
		t.Fatal("Create() returned an empty token")
	}

	userID, err := s.Lookup(token)
	if err != nil {
		t.Fatalf("Lookup() returned error: %v", err)
	}
	if userID != "user-1" {
		t.Errorf("Lookup() userID = %q, want %q", userID, "user-1")
	}
}

// TestSessionTokenIsNotStoredInClear is the load-bearing test of this file:
// it reads the sessions table directly, the way an attacker with read access
// to the database would, and checks that the token Create() handed back
// appears nowhere in it — not as the stored hash, not as a substring of any
// other column.
func TestSessionTokenIsNotStoredInClear(t *testing.T) {
	s, db := newTestSessionStore(t, time.Hour)
	createTestUser(t, db, "user-1")

	token, err := s.Create("user-1")
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}

	rows, err := db.Query(`SELECT token_hash, user_id, created_at, expires_at FROM sessions`)
	if err != nil {
		t.Fatalf("query sessions table: %v", err)
	}
	defer func() { _ = rows.Close() }()

	found := false
	for rows.Next() {
		var tokenHash, userID, createdAt, expiresAt string
		if err := rows.Scan(&tokenHash, &userID, &createdAt, &expiresAt); err != nil {
			t.Fatalf("scan session row: %v", err)
		}
		found = true

		if tokenHash == token {
			t.Fatal("token_hash column stores the raw token verbatim")
		}
		for _, field := range []string{tokenHash, userID, createdAt, expiresAt} {
			if strings.Contains(field, token) {
				t.Fatalf("session row contains the raw token as a substring: %q", field)
			}
		}
	}
	if err := rows.Err(); err != nil {
		t.Fatalf("iterate sessions: %v", err)
	}
	if !found {
		t.Fatal("no session row found after Create()")
	}
}

// TestSessionLookupRejectsExpired uses a negative TTL so the session is
// already expired the instant Create() writes it — no sleeping required to
// prove the expiry check runs at Lookup time, not only in some later cleanup
// pass.
func TestSessionLookupRejectsExpired(t *testing.T) {
	s, db := newTestSessionStore(t, -time.Second)
	createTestUser(t, db, "user-1")

	token, err := s.Create("user-1")
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}

	if _, err := s.Lookup(token); !errors.Is(err, ErrSessionInvalid) {
		t.Fatalf("Lookup() error = %v, want ErrSessionInvalid", err)
	}

	var count int
	if err := db.QueryRow(`SELECT COUNT(*) FROM sessions`).Scan(&count); err != nil {
		t.Fatalf("count sessions: %v", err)
	}
	if count != 0 {
		t.Errorf("sessions table has %d rows after an expired Lookup(), want 0 (expired row should be removed)", count)
	}
}

func TestSessionRotateInvalidatesTheOldToken(t *testing.T) {
	s, db := newTestSessionStore(t, time.Hour)
	createTestUser(t, db, "user-1")

	oldToken, err := s.Create("user-1")
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}

	newToken, err := s.Rotate(oldToken, "user-1")
	if err != nil {
		t.Fatalf("Rotate() returned error: %v", err)
	}
	if newToken == oldToken {
		t.Fatal("Rotate() returned the same token as the one it should have invalidated")
	}

	if _, err := s.Lookup(oldToken); !errors.Is(err, ErrSessionInvalid) {
		t.Fatalf("Lookup(oldToken) after Rotate() error = %v, want ErrSessionInvalid", err)
	}

	userID, err := s.Lookup(newToken)
	if err != nil {
		t.Fatalf("Lookup(newToken) returned error: %v", err)
	}
	if userID != "user-1" {
		t.Errorf("Lookup(newToken) userID = %q, want %q", userID, "user-1")
	}
}

func TestSessionDestroyInvalidates(t *testing.T) {
	s, db := newTestSessionStore(t, time.Hour)
	createTestUser(t, db, "user-1")

	token, err := s.Create("user-1")
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}

	if err := s.Destroy(token); err != nil {
		t.Fatalf("Destroy() returned error: %v", err)
	}

	if _, err := s.Lookup(token); !errors.Is(err, ErrSessionInvalid) {
		t.Fatalf("Lookup() after Destroy() error = %v, want ErrSessionInvalid", err)
	}
}

// TestSessionDestroyIsIdempotent covers a logout on a token that is already
// gone (double-click, replayed request, expired session): the caller's goal
// — this token authenticates nothing — already holds, so it is not an error.
func TestSessionDestroyIsIdempotent(t *testing.T) {
	s, _ := newTestSessionStore(t, time.Hour)

	if err := s.Destroy("token-that-was-never-created"); err != nil {
		t.Fatalf("Destroy() on an unknown token returned error: %v", err)
	}
}

func TestSessionLookupRejectsUnknownToken(t *testing.T) {
	s, _ := newTestSessionStore(t, time.Hour)

	if _, err := s.Lookup("token-that-was-never-created"); !errors.Is(err, ErrSessionInvalid) {
		t.Fatalf("Lookup() error = %v, want ErrSessionInvalid", err)
	}
}

// TestSessionLookupFailsOnMalformedExpiry plants a row directly — Create()
// can never produce a malformed expires_at itself — to exercise the parse
// failure branch Lookup must still handle loudly rather than misread as
// either valid or expired.
func TestSessionLookupFailsOnMalformedExpiry(t *testing.T) {
	s, db := newTestSessionStore(t, time.Hour)
	createTestUser(t, db, "user-1")

	_, err := db.Exec(
		`INSERT INTO sessions (token_hash, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)`,
		hashToken("planted-token"), "user-1", formatTime(time.Now()), "not-a-timestamp",
	)
	if err != nil {
		t.Fatalf("plant malformed session row: %v", err)
	}

	_, err = s.Lookup("planted-token")
	if err == nil {
		t.Fatal("Lookup() returned no error for a malformed expiry, want error")
	}
	if !strings.Contains(err.Error(), "parse expiry") {
		t.Errorf("Lookup() error = %q, want it to mention parse expiry", err.Error())
	}
}

func TestSessionLookupFailsOnQueryError(t *testing.T) {
	s, db := newTestSessionStore(t, time.Hour)
	if err := db.Close(); err != nil {
		t.Fatalf("close database: %v", err)
	}

	_, err := s.Lookup("irrelevant-token")
	if err == nil {
		t.Fatal("Lookup() returned no error after the database was closed, want error")
	}
	if errors.Is(err, ErrSessionInvalid) {
		t.Fatal("Lookup() returned ErrSessionInvalid for a database error, want a distinct error that says so")
	}
}

// generateToken's random-source-failure branch is not covered here: as of
// Go 1.24, crypto/rand.Read never returns an error to its caller — a failed
// read crashes the whole process instead of coming back as an error value
// (see the comment on generateToken in session.go). Simulating that failure
// by swapping crypto/rand.Reader was tried and confirmed to take the entire
// test binary down with runtime.fatal rather than fail one test, so there is
// no way to exercise that branch from a test, and no branch left to exercise
// in generateToken itself since it no longer returns an error at all.

func TestSessionRotateFailsWhenDatabaseUnavailable(t *testing.T) {
	s, db := newTestSessionStore(t, time.Hour)
	if err := db.Close(); err != nil {
		t.Fatalf("close database: %v", err)
	}

	if _, err := s.Rotate("old-token", "user-1"); err == nil {
		t.Fatal("Rotate() returned no error after the database was closed, want error")
	}
}

// TestSessionRotateFailsWhenNewSessionCannotBeCreated exercises Rotate's
// second error-wrapping branch (the one around Create, distinct from the one
// around the delete): the old token's delete succeeds (there is nothing to
// delete, which is not an error — see TestSessionDestroyIsIdempotent for the
// same idea), but the foreign key on sessions.user_id rejects the fresh
// session because "ghost" was never inserted into users.
func TestSessionRotateFailsWhenNewSessionCannotBeCreated(t *testing.T) {
	s, _ := newTestSessionStore(t, time.Hour)

	if _, err := s.Rotate("old-token", "ghost"); err == nil {
		t.Fatal("Rotate() returned no error for an unknown user, want error")
	}
}

func TestSessionDestroyFailsWhenDatabaseUnavailable(t *testing.T) {
	s, db := newTestSessionStore(t, time.Hour)
	if err := db.Close(); err != nil {
		t.Fatalf("close database: %v", err)
	}

	if err := s.Destroy("some-token"); err == nil {
		t.Fatal("Destroy() returned no error after the database was closed, want error")
	}
}
