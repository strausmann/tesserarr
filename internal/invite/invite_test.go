package invite

import (
	"database/sql"
	"errors"
	"fmt"
	"path/filepath"
	"sync"
	"testing"
	"time"

	"github.com/strausmann/tesserarr/internal/store"
)

// newTestDB opens a fresh database with the real embedded migrations applied,
// so tests exercise the actual invitations/users schema, not a stand-in.
func newTestDB(t *testing.T) *sql.DB {
	t.Helper()
	st, err := store.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("store.Open() returned error: %v", err)
	}
	t.Cleanup(func() { _ = st.Close() })
	return st.DB()
}

// mustCreateUser inserts the row Redeem's redeemed_by foreign key requires.
// Real callers create the user as part of identity resolution before ever
// calling Redeem; tests do the same thing by hand.
func mustCreateUser(t *testing.T, db *sql.DB, id string) {
	t.Helper()
	_, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		id, "Test User "+id, time.Now().UTC().Format(timeFormat),
	)
	if err != nil {
		t.Fatalf("insert test user %q: %v", id, err)
	}
}

func TestCreateStoresOnlyTheHash(t *testing.T) {
	db := newTestDB(t)

	token, err := Create(db, 0)
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}
	if token == "" {
		t.Fatal("Create() returned an empty token")
	}

	wantHash := hashToken(token)
	var storedHash string
	if err := db.QueryRow(`SELECT token_hash FROM invitations`).Scan(&storedHash); err != nil {
		t.Fatalf("read stored invitation: %v", err)
	}
	if storedHash != wantHash {
		t.Errorf("stored token_hash = %q, want %q (sha256 of the plaintext)", storedHash, wantHash)
	}
	if storedHash == token {
		t.Fatal("token_hash column holds the plaintext token verbatim, not its hash")
	}

	// The plaintext itself must not be findable in the column at all — not
	// merely "not equal to some other value we happened to check".
	var unused string
	err = db.QueryRow(`SELECT token_hash FROM invitations WHERE token_hash = ?`, token).Scan(&unused)
	if !errors.Is(err, sql.ErrNoRows) {
		t.Fatalf("looking the plaintext token up directly as if it were the hash found a row (err=%v) — "+
			"the plaintext must not be stored anywhere queryable", err)
	}
}

func TestRedeemSucceedsOnce(t *testing.T) {
	db := newTestDB(t)
	mustCreateUser(t, db, "user-1")

	token, err := Create(db, 0)
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}

	if err := Redeem(db, token, "user-1"); err != nil {
		t.Fatalf("Redeem() returned error: %v", err)
	}

	var redeemedBy, redeemedAt sql.NullString
	err = db.QueryRow(
		`SELECT redeemed_by, redeemed_at FROM invitations WHERE token_hash = ?`, hashToken(token),
	).Scan(&redeemedBy, &redeemedAt)
	if err != nil {
		t.Fatalf("read redeemed row: %v", err)
	}
	if !redeemedBy.Valid || redeemedBy.String != "user-1" {
		t.Errorf("redeemed_by = %+v, want \"user-1\"", redeemedBy)
	}
	if !redeemedAt.Valid || redeemedAt.String == "" {
		t.Error("redeemed_at was left unset")
	}
}

func TestRedeemRejectsUnknownToken(t *testing.T) {
	db := newTestDB(t)
	mustCreateUser(t, db, "user-1")

	err := Redeem(db, "this-token-was-never-created", "user-1")
	if !errors.Is(err, ErrNotFound) {
		t.Errorf("Redeem() error = %v, want ErrNotFound", err)
	}
}

func TestRedeemRejectsExpiredToken(t *testing.T) {
	db := newTestDB(t)
	mustCreateUser(t, db, "user-1")

	// Built directly rather than through Create: Create's ttl<=0 means "use
	// the default", so an already-expired fixture needs the lower-level
	// constructor instead of a (deliberately unsupported) negative ttl.
	token := "fixture-token-expired"
	validUntil := time.Now().UTC().Add(-1 * time.Hour)
	if err := insertInvitation(db, token, validUntil.Add(-1*time.Hour), validUntil); err != nil {
		t.Fatalf("insertInvitation() returned error: %v", err)
	}

	err := Redeem(db, token, "user-1")
	if !errors.Is(err, ErrExpired) {
		t.Errorf("Redeem() error = %v, want ErrExpired", err)
	}
}

func TestRedeemRejectsRevokedToken(t *testing.T) {
	db := newTestDB(t)
	mustCreateUser(t, db, "user-1")

	token, err := Create(db, 0)
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}
	if err := Revoke(db, token); err != nil {
		t.Fatalf("Revoke() returned error: %v", err)
	}

	err = Redeem(db, token, "user-1")
	if !errors.Is(err, ErrRevoked) {
		t.Errorf("Redeem() error = %v, want ErrRevoked", err)
	}
}

func TestRedeemRejectsSecondAttempt(t *testing.T) {
	db := newTestDB(t)
	mustCreateUser(t, db, "user-1")
	mustCreateUser(t, db, "user-2")

	token, err := Create(db, 0)
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}
	if err := Redeem(db, token, "user-1"); err != nil {
		t.Fatalf("first Redeem() returned error: %v", err)
	}

	err = Redeem(db, token, "user-2")
	if !errors.Is(err, ErrAlreadyRedeemed) {
		t.Errorf("second Redeem() error = %v, want ErrAlreadyRedeemed", err)
	}

	// The rejected second attempt must not have overwritten the winner.
	var redeemedBy string
	if err := db.QueryRow(
		`SELECT redeemed_by FROM invitations WHERE token_hash = ?`, hashToken(token),
	).Scan(&redeemedBy); err != nil {
		t.Fatalf("read redeemed_by: %v", err)
	}
	if redeemedBy != "user-1" {
		t.Errorf("redeemed_by = %q, want \"user-1\" (unchanged by the rejected second attempt)", redeemedBy)
	}
}

func TestCreateReturnsErrorWhenDatabaseIsClosed(t *testing.T) {
	db := newTestDB(t)
	if err := db.Close(); err != nil {
		t.Fatalf("close db: %v", err)
	}

	if _, err := Create(db, 0); err == nil {
		t.Fatal("Create() returned no error against a closed database")
	}
}

func TestCreateReturnsErrorWhenRandomSourceFails(t *testing.T) {
	db := newTestDB(t)
	original := randRead
	randRead = func([]byte) (int, error) { return 0, errors.New("simulated entropy failure") }
	t.Cleanup(func() { randRead = original })

	if _, err := Create(db, 0); err == nil {
		t.Fatal("Create() returned no error when the random source failed")
	}
}

func TestRedeemReturnsErrorWhenDatabaseIsClosed(t *testing.T) {
	db := newTestDB(t)
	if err := db.Close(); err != nil {
		t.Fatalf("close db: %v", err)
	}

	err := Redeem(db, "irrelevant-token", "user-1")
	if err == nil {
		t.Fatal("Redeem() returned no error against a closed database")
	}
	if errors.Is(err, ErrNotFound) || errors.Is(err, ErrExpired) || errors.Is(err, ErrRevoked) || errors.Is(err, ErrAlreadyRedeemed) {
		t.Errorf("Redeem() reported sentinel error %v for a database failure, want a wrapped database error", err)
	}
}

func TestRevokeReturnsErrorWhenDatabaseIsClosed(t *testing.T) {
	db := newTestDB(t)
	if err := db.Close(); err != nil {
		t.Fatalf("close db: %v", err)
	}

	if err := Revoke(db, "irrelevant-token"); err == nil {
		t.Fatal("Revoke() returned no error against a closed database")
	}
}

func TestRevokeRejectsUnknownToken(t *testing.T) {
	db := newTestDB(t)

	err := Revoke(db, "this-token-was-never-created")
	if !errors.Is(err, ErrNotFound) {
		t.Errorf("Revoke() error = %v, want ErrNotFound", err)
	}
}

// TestExplainRejectionReportsDatabaseErrorsThatAreNotNoRows exercises the
// branch that distinguishes a genuine database failure (must be wrapped and
// returned) from an absent row (must become ErrNotFound) — a distinction
// Redeem's own tests never isolate because Redeem's own UPDATE always fails
// first against a closed database, before explainRejection ever runs.
func TestExplainRejectionReportsDatabaseErrorsThatAreNotNoRows(t *testing.T) {
	db := newTestDB(t)
	if err := db.Close(); err != nil {
		t.Fatalf("close db: %v", err)
	}

	err := explainRejection(db, "irrelevant-hash", time.Now().UTC().Format(timeFormat))
	if err == nil {
		t.Fatal("explainRejection() returned no error against a closed database")
	}
	if errors.Is(err, ErrNotFound) {
		t.Error("explainRejection() reported ErrNotFound for a database error, want a wrapped error instead")
	}
}

// TestExplainRejectionDefaultsToAlreadyRedeemedWhenRowLooksValid exercises
// the branch explainRejection takes when, by the time it re-reads the row,
// every condition looks satisfied after all — as if another caller changed
// the row in the gap between Redeem's UPDATE and this read. Constructed
// directly against explainRejection rather than through a genuine race,
// which TestRedeemConcurrentAttemptsExactlyOneSucceeds already covers.
func TestExplainRejectionDefaultsToAlreadyRedeemedWhenRowLooksValid(t *testing.T) {
	db := newTestDB(t)
	token, err := Create(db, 0)
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}

	err = explainRejection(db, hashToken(token), time.Now().UTC().Format(timeFormat))
	if !errors.Is(err, ErrAlreadyRedeemed) {
		t.Errorf("explainRejection() = %v, want ErrAlreadyRedeemed for an apparently-still-valid row", err)
	}
}

// TestRedeemConcurrentAttemptsExactlyOneSucceeds is the test the brief calls
// "tragend": two (here, several) parties race to redeem the same token, as
// happens for real when a messaging app's link-preview fetcher and the
// actual recipient both hit the link within milliseconds of each other.
// Exactly one attempt may win; every other attempt must observe the token
// already claimed.
func TestRedeemConcurrentAttemptsExactlyOneSucceeds(t *testing.T) {
	db := newTestDB(t)
	mustCreateUser(t, db, "user-a")
	mustCreateUser(t, db, "user-b")

	token, err := Create(db, 0)
	if err != nil {
		t.Fatalf("Create() returned error: %v", err)
	}

	const attempts = 8
	start := make(chan struct{})
	var wg sync.WaitGroup
	results := make([]error, attempts)
	for i := 0; i < attempts; i++ {
		userID := fmt.Sprintf("user-%c", 'a'+rune(i%2))
		wg.Add(1)
		go func(i int, userID string) {
			defer wg.Done()
			<-start // released all at once, to make the race as tight as possible
			results[i] = Redeem(db, token, userID)
		}(i, userID)
	}
	close(start)
	wg.Wait()

	successes := 0
	for i, err := range results {
		if err == nil {
			successes++
			continue
		}
		if !errors.Is(err, ErrAlreadyRedeemed) {
			t.Errorf("attempt %d: error = %v, want nil (won) or ErrAlreadyRedeemed (lost)", i, err)
		}
	}
	if successes != 1 {
		t.Fatalf("got %d successful redemptions out of %d concurrent attempts, want exactly 1", successes, attempts)
	}
}
