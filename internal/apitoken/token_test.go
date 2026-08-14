package apitoken

import (
	"database/sql"
	"errors"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/strausmann/tesserarr/internal/store"
)

// newTestStore opens a fully migrated database and returns both the Store
// under test and the raw *sql.DB, so tests that need to read the api_tokens
// table directly (TestTokenIsNotStoredInClear), plant a row Create() could
// never produce (TestListFailsOnMalformedCreatedAt), or pull the handle out
// from under the store (the closed-database tests) can do so without
// re-implementing store.Open's migration dance.
func newTestStore(t *testing.T) (*Store, *sql.DB) {
	t.Helper()
	st, err := store.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("store.Open() returned error: %v", err)
	}
	t.Cleanup(func() { _ = st.Close() })

	db := st.DB()
	return New(db), db
}

// createTestUser inserts the minimal row the api_tokens table's foreign key
// on user_id requires.
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

func TestCreateAndLookup(t *testing.T) {
	s, db := newTestStore(t)
	createTestUser(t, db, "user-1")

	token, _, err := s.Create("user-1", "sonarr", 0)
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

// TestTokenIsNotStoredInClear is the load-bearing test of this file, mirrored
// on identity's TestSessionTokenIsNotStoredInClear: it reads the api_tokens
// table directly, the way an attacker with read access to the database
// would, and checks that the token Create() handed back appears nowhere in
// it — not as the stored hash, not as a substring of any other column.
func TestTokenIsNotStoredInClear(t *testing.T) {
	s, db := newTestStore(t)
	createTestUser(t, db, "user-1")

	token, _, err := s.Create("user-1", "sonarr", 0)
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}

	rows, err := db.Query(`SELECT token_hash, user_id, label, created_at FROM api_tokens`)
	if err != nil {
		t.Fatalf("query api_tokens table: %v", err)
	}
	defer func() { _ = rows.Close() }()

	found := false
	for rows.Next() {
		var tokenHash, userID, label, createdAt string
		if err := rows.Scan(&tokenHash, &userID, &label, &createdAt); err != nil {
			t.Fatalf("scan api_tokens row: %v", err)
		}
		found = true

		if tokenHash == token {
			t.Fatal("token_hash column stores the raw token verbatim")
		}
		for _, field := range []string{tokenHash, userID, label, createdAt} {
			if strings.Contains(field, token) {
				t.Fatalf("api_tokens row contains the raw token as a substring: %q", field)
			}
		}
	}
	if err := rows.Err(); err != nil {
		t.Fatalf("iterate api_tokens: %v", err)
	}
	if !found {
		t.Fatal("no api_tokens row found after Create()")
	}
}

func TestLookupRejectsUnknownToken(t *testing.T) {
	s, _ := newTestStore(t)

	if _, err := s.Lookup("token-that-was-never-created"); !errors.Is(err, ErrTokenInvalid) {
		t.Fatalf("Lookup() error = %v, want ErrTokenInvalid", err)
	}
}

// TestLookupRecordsLastUsed is the direct test of the best-effort side
// effect Lookup's doc comment describes: LastUsedAt is nil before the token
// is ever looked up, and set afterwards.
func TestLookupRecordsLastUsed(t *testing.T) {
	s, db := newTestStore(t)
	createTestUser(t, db, "user-1")

	token, _, err := s.Create("user-1", "sonarr", 0)
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}

	before, err := s.List("user-1")
	if err != nil {
		t.Fatalf("List() before Lookup() returned error: %v", err)
	}
	if len(before) != 1 || before[0].LastUsedAt != nil {
		t.Fatalf("List() before Lookup() = %+v, want one token with LastUsedAt == nil", before)
	}

	if _, err := s.Lookup(token); err != nil {
		t.Fatalf("Lookup() returned error: %v", err)
	}

	after, err := s.List("user-1")
	if err != nil {
		t.Fatalf("List() after Lookup() returned error: %v", err)
	}
	if len(after) != 1 || after[0].LastUsedAt == nil {
		t.Fatalf("List() after Lookup() = %+v, want one token with LastUsedAt set", after)
	}
}

func TestLookupFailsOnQueryError(t *testing.T) {
	s, db := newTestStore(t)
	if err := db.Close(); err != nil {
		t.Fatalf("close database: %v", err)
	}

	_, err := s.Lookup("irrelevant-token")
	if err == nil {
		t.Fatal("Lookup() returned no error after the database was closed, want error")
	}
	if errors.Is(err, ErrTokenInvalid) {
		t.Fatal("Lookup() returned ErrTokenInvalid for a database error, want a distinct error that says so")
	}
}

// generateToken's random-source-failure branch is not covered here, for the
// same reason identity.SessionStore's session_test.go leaves it uncovered:
// as of Go 1.24, crypto/rand.Read never returns an error to its caller — a
// failed read crashes the whole process instead of coming back as an error
// value (see the comment on generateToken in token.go).

func TestCreateFailsOnDatabaseError(t *testing.T) {
	s, _ := newTestStore(t)

	// "ghost" was never inserted into users, so the foreign key on
	// api_tokens.user_id rejects the insert.
	if _, _, err := s.Create("ghost", "sonarr", 0); err == nil {
		t.Fatal("Create() returned no error for an unknown user, want error")
	}
}

func TestRevokeInvalidates(t *testing.T) {
	s, db := newTestStore(t)
	createTestUser(t, db, "user-1")

	token, _, err := s.Create("user-1", "sonarr", 0)
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}

	tokens, err := s.List("user-1")
	if err != nil {
		t.Fatalf("List() returned error: %v", err)
	}
	if len(tokens) != 1 {
		t.Fatalf("List() returned %d tokens, want 1", len(tokens))
	}

	if err := s.Revoke("user-1", tokens[0].Hash); err != nil {
		t.Fatalf("Revoke() returned error: %v", err)
	}

	if _, err := s.Lookup(token); !errors.Is(err, ErrTokenInvalid) {
		t.Fatalf("Lookup() after Revoke() error = %v, want ErrTokenInvalid", err)
	}
}

// TestRevokeIsIdempotent covers revoking a token that is already gone
// (double-click, replayed request, a hash that was never issued): the
// caller's goal — this token authenticates nothing — already holds, so it is
// not an error. Mirrors identity.SessionStore's TestSessionDestroyIsIdempotent.
func TestRevokeIsIdempotent(t *testing.T) {
	s, _ := newTestStore(t)

	if err := s.Revoke("no-such-user", "hash-that-was-never-created"); err != nil {
		t.Fatalf("Revoke() on an unknown hash returned error: %v", err)
	}
}

func TestRevokeFailsWhenDatabaseUnavailable(t *testing.T) {
	s, db := newTestStore(t)
	if err := db.Close(); err != nil {
		t.Fatalf("close database: %v", err)
	}

	if err := s.Revoke("no-such-user", "some-hash"); err == nil {
		t.Fatal("Revoke() returned no error after the database was closed, want error")
	}
}

// TestListDoesNotExposeTheRawToken plants two tokens for the same user and
// checks the raw values Create() returned appear nowhere in what List()
// hands back — the same "read the way an attacker would" approach as
// TestTokenIsNotStoredInClear, but against the API surface a caller actually
// uses instead of against the raw table.
func TestListDoesNotExposeTheRawToken(t *testing.T) {
	s, db := newTestStore(t)
	createTestUser(t, db, "user-1")

	tokenA, _, err := s.Create("user-1", "sonarr", 0)
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}
	tokenB, _, err := s.Create("user-1", "radarr", 0)
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}

	tokens, err := s.List("user-1")
	if err != nil {
		t.Fatalf("List() returned error: %v", err)
	}
	if len(tokens) != 2 {
		t.Fatalf("List() returned %d tokens, want 2", len(tokens))
	}

	for _, tok := range tokens {
		if tok.Hash == tokenA || tok.Hash == tokenB {
			t.Fatal("List() returned a raw token value in the Hash field")
		}
		if strings.Contains(tok.Label, tokenA) || strings.Contains(tok.Label, tokenB) {
			t.Fatal("List() leaked a raw token into the Label field")
		}
	}
}

// TestListOrdersMostRecentFirst plants two tokens with created_at values far
// enough apart to be unambiguous, and checks List() returns the newer one
// first — the order the doc comment on List promises.
func TestListOrdersMostRecentFirst(t *testing.T) {
	s, db := newTestStore(t)
	createTestUser(t, db, "user-1")

	older := formatTime(time.Now().Add(-time.Hour))
	newer := formatTime(time.Now())
	if _, err := db.Exec(
		`INSERT INTO api_tokens (token_hash, user_id, label, created_at) VALUES (?, ?, ?, ?)`,
		hashToken("token-older"), "user-1", "older", older,
	); err != nil {
		t.Fatalf("plant older token row: %v", err)
	}
	if _, err := db.Exec(
		`INSERT INTO api_tokens (token_hash, user_id, label, created_at) VALUES (?, ?, ?, ?)`,
		hashToken("token-newer"), "user-1", "newer", newer,
	); err != nil {
		t.Fatalf("plant newer token row: %v", err)
	}

	tokens, err := s.List("user-1")
	if err != nil {
		t.Fatalf("List() returned error: %v", err)
	}
	if len(tokens) != 2 {
		t.Fatalf("List() returned %d tokens, want 2", len(tokens))
	}
	if tokens[0].Label != "newer" || tokens[1].Label != "older" {
		t.Errorf("List() order = [%q, %q], want [\"newer\", \"older\"]", tokens[0].Label, tokens[1].Label)
	}
}

func TestListReturnsEmptyForUnknownUser(t *testing.T) {
	s, _ := newTestStore(t)

	tokens, err := s.List("nobody")
	if err != nil {
		t.Fatalf("List() returned error: %v", err)
	}
	if len(tokens) != 0 {
		t.Errorf("List() returned %d tokens for an unknown user, want 0", len(tokens))
	}
}

func TestListFailsOnQueryError(t *testing.T) {
	s, db := newTestStore(t)
	if err := db.Close(); err != nil {
		t.Fatalf("close database: %v", err)
	}

	if _, err := s.List("user-1"); err == nil {
		t.Fatal("List() returned no error after the database was closed, want error")
	}
}

// TestListFailsOnMalformedCreatedAt plants a row directly — Create() can
// never produce a malformed created_at itself — to exercise the parse
// failure branch List must still handle loudly rather than misread as a
// zero time.
func TestListFailsOnMalformedCreatedAt(t *testing.T) {
	s, db := newTestStore(t)
	createTestUser(t, db, "user-1")

	if _, err := db.Exec(
		`INSERT INTO api_tokens (token_hash, user_id, label, created_at) VALUES (?, ?, ?, ?)`,
		hashToken("planted-token"), "user-1", "sonarr", "not-a-timestamp",
	); err != nil {
		t.Fatalf("plant malformed api_tokens row: %v", err)
	}

	_, err := s.List("user-1")
	if err == nil {
		t.Fatal("List() returned no error for a malformed created_at, want error")
	}
	if !strings.Contains(err.Error(), "parse created_at") {
		t.Errorf("List() error = %q, want it to mention parse created_at", err.Error())
	}
}

// TestListFailsOnMalformedLastUsedAt is the same idea as
// TestListFailsOnMalformedCreatedAt, aimed at the second, independent parse
// branch List has: last_used_at is only parsed when it is non-NULL, so a
// malformed created_at cannot exercise this branch on its own.
func TestListFailsOnMalformedLastUsedAt(t *testing.T) {
	s, db := newTestStore(t)
	createTestUser(t, db, "user-1")

	if _, err := db.Exec(
		`INSERT INTO api_tokens (token_hash, user_id, label, created_at, last_used_at) VALUES (?, ?, ?, ?, ?)`,
		hashToken("planted-token"), "user-1", "sonarr", formatTime(time.Now()), "not-a-timestamp",
	); err != nil {
		t.Fatalf("plant malformed api_tokens row: %v", err)
	}

	_, err := s.List("user-1")
	if err == nil {
		t.Fatal("List() returned no error for a malformed last_used_at, want error")
	}
	if !strings.Contains(err.Error(), "parse last_used_at") {
		t.Errorf("List() error = %q, want it to mention parse last_used_at", err.Error())
	}
}

// Verifikationspunkt 5: ein abgelaufener Token liefert dasselbe Ergebnis
// wie ein widerrufener.
func TestLookupTreatsExpiredTokenLikeInvalid(t *testing.T) {
	_, db := newTestStore(t)
	createTestUser(t, db, "user-1")
	s := New(db)

	const rawToken = "already-expired-raw-token-value"
	past := time.Now().Add(-time.Hour)
	if err := insertToken(db, rawToken, "user-1", "sonarr", time.Now().Add(-2*time.Hour), &past); err != nil {
		t.Fatalf("insertToken() returned error: %v", err)
	}

	if _, err := s.Lookup(rawToken); !errors.Is(err, ErrTokenInvalid) {
		t.Errorf("Lookup() error = %v, want ErrTokenInvalid", err)
	}
}

func TestLookupAcceptsTokenBeforeItsExpiry(t *testing.T) {
	s, db := newTestStore(t)
	createTestUser(t, db, "user-1")

	token, _, err := s.Create("user-1", "sonarr", time.Hour)
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}

	if _, err := s.Lookup(token); err != nil {
		t.Errorf("Lookup() before expiry returned error: %v", err)
	}
}

func TestCreateWithZeroTTLNeverExpires(t *testing.T) {
	s, db := newTestStore(t)
	createTestUser(t, db, "user-1")

	_, meta, err := s.Create("user-1", "sonarr", 0)
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}
	if meta.ExpiresAt != nil {
		t.Errorf("ExpiresAt = %v, want nil (never expires)", meta.ExpiresAt)
	}
}

func TestCreateReturnsMetadataMatchingTheStoredRow(t *testing.T) {
	s, db := newTestStore(t)
	createTestUser(t, db, "user-1")

	_, meta, err := s.Create("user-1", "sonarr", time.Hour)
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}
	if meta.Label != "sonarr" {
		t.Errorf("meta.Label = %q, want sonarr", meta.Label)
	}
	if meta.ExpiresAt == nil {
		t.Fatal("meta.ExpiresAt = nil, want a set expiry")
	}

	tokens, err := s.List("user-1")
	if err != nil {
		t.Fatalf("List() returned error: %v", err)
	}
	if len(tokens) != 1 || tokens[0].Hash != meta.Hash {
		t.Errorf("List() = %v, want exactly one token with hash %q", tokens, meta.Hash)
	}
}

// Ownership scoping: revoking with the WRONG user id must not delete
// another user's token — the HTTP layer (internal/api, Aufgabe 7) relies
// on this to let any signed-in user call Revoke with only a hash, safely.
func TestRevokeDoesNotDeleteAnotherUsersToken(t *testing.T) {
	s, db := newTestStore(t)
	createTestUser(t, db, "user-1")
	createTestUser(t, db, "user-2")

	token, meta, err := s.Create("user-1", "sonarr", 0)
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}

	if err := s.Revoke("user-2", meta.Hash); err != nil {
		t.Fatalf("Revoke() by the wrong user returned error: %v", err)
	}

	if _, err := s.Lookup(token); err != nil {
		t.Errorf("Lookup() after a wrong-owner Revoke() attempt returned error: %v, want the token to still work", err)
	}
}
