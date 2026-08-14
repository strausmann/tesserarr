// Package invite manages single-use invitation tokens: minting them, and
// redeeming them exactly once even when two redemption attempts race — for
// example when a messaging app's link-preview fetcher and the actual
// recipient both hit the same invite link within milliseconds of each
// other.
package invite

import (
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"errors"
	"fmt"
	"time"
)

// tokenBytes is the amount of randomness in a minted token. 32 bytes (256
// bits) comfortably clears the 128-bit floor the concept requires.
const tokenBytes = 32

// DefaultTTL is how long a freshly minted invitation stays valid when the
// caller does not ask for a different lifetime.
const DefaultTTL = 7 * 24 * time.Hour

// Sentinel errors Redeem returns, distinguishing why a token was rejected.
// Callers that only care whether redemption worked can check err != nil;
// callers that want to explain the rejection use errors.Is against these.
var (
	ErrNotFound        = errors.New("invite: token not found")
	ErrExpired         = errors.New("invite: token expired")
	ErrRevoked         = errors.New("invite: token revoked")
	ErrAlreadyRedeemed = errors.New("invite: token already redeemed")
)

// timeFormat is RFC3339 in UTC (always ending "Z", never a numeric offset).
// For UTC timestamps specifically this format is both fixed-width and
// lexicographically ordered the same as chronologically ordered, which is
// what lets Redeem's "valid_until > ?" comparison run as a plain TEXT
// comparison in SQL instead of parsing dates back out of the database.
const timeFormat = time.RFC3339

// Create mints a new single-use invitation and returns the plaintext token.
// This is the only place the plaintext ever exists: the database stores only
// its SHA-256 hash (see hashToken), so a leaked backup or a compromised read
// replica cannot be turned back into a usable invitation.
//
// ttl is how long the invitation stays valid; zero or negative selects
// DefaultTTL. There is deliberately no way to mint an already-expired
// invitation through this constructor — a caller with a ttl bug should get
// a working invitation with the default lifetime, not a silently dead one.
func Create(db *sql.DB, ttl time.Duration) (string, error) {
	if ttl <= 0 {
		ttl = DefaultTTL
	}
	token, err := randomToken()
	if err != nil {
		return "", err
	}
	now := time.Now().UTC()
	if err := insertInvitation(db, token, now, now.Add(ttl)); err != nil {
		return "", err
	}
	return token, nil
}

// insertInvitation writes a new invitations row for token, valid from
// createdAt through validUntil. Split out from Create so tests can build
// fixtures (e.g. an already-expired invitation) that Create's public
// contract intentionally does not allow constructing.
func insertInvitation(db *sql.DB, token string, createdAt, validUntil time.Time) error {
	_, err := db.Exec(
		`INSERT INTO invitations (token_hash, created_at, valid_until, revoked) VALUES (?, ?, ?, 0)`,
		hashToken(token), createdAt.Format(timeFormat), validUntil.Format(timeFormat),
	)
	if err != nil {
		return fmt.Errorf("invite: create: %w", err)
	}
	return nil
}

// Redeem atomically claims token for userID. Exactly one of any number of
// concurrent Redeem calls for the same token succeeds; the rest observe the
// row already claimed (or expired/revoked) and return the matching sentinel
// error.
//
// The single UPDATE below — guarded by redeemed_by IS NULL and evaluated
// through its affected row count — IS the atomicity: there is no separate
// read-then-check-then-write, so there is no window between a check and a
// write for a second caller to land in. A naive read-then-write
// implementation was built and discarded during development specifically
// because it let every concurrent attempt "win" (see invite_test.go,
// TestRedeemConcurrentAttemptsExactlyOneSucceeds, and the task report for
// what that looked like).
func Redeem(db *sql.DB, rawToken, userID string) error {
	hash := hashToken(rawToken)
	now := time.Now().UTC().Format(timeFormat)

	result, err := db.Exec(
		`UPDATE invitations
		    SET redeemed_by = ?, redeemed_at = ?
		  WHERE token_hash = ?
		    AND redeemed_by IS NULL
		    AND revoked = 0
		    AND valid_until > ?`,
		userID, now, hash, now,
	)
	if err != nil {
		return fmt.Errorf("invite: redeem: %w", err)
	}

	affected, err := result.RowsAffected()
	if err != nil {
		return fmt.Errorf("invite: redeem: rows affected: %w", err)
	}
	if affected == 1 {
		return nil
	}

	return explainRejection(db, hash, now)
}

// explainRejection re-reads the row Redeem just failed to claim and maps its
// state to the sentinel error that best describes why. It is called only
// after Redeem's own atomic UPDATE has already affected zero rows, so the
// security-relevant decision (redeem or not) is already final by the time
// this runs — this second read only chooses which error to report. If the
// row's state moves again between the UPDATE and this read (e.g. another
// caller redeemed or revoked it in between), the explanation may be a beat
// stale, but it can never turn a redemption that did not happen into a
// reported success.
func explainRejection(db *sql.DB, hash, now string) error {
	var revoked int
	var redeemedBy sql.NullString
	var validUntil string
	err := db.QueryRow(
		`SELECT revoked, redeemed_by, valid_until FROM invitations WHERE token_hash = ?`, hash,
	).Scan(&revoked, &redeemedBy, &validUntil)
	switch {
	case errors.Is(err, sql.ErrNoRows):
		return ErrNotFound
	case err != nil:
		return fmt.Errorf("invite: explain rejection: %w", err)
	case revoked != 0:
		return ErrRevoked
	case redeemedBy.Valid:
		return ErrAlreadyRedeemed
	case validUntil <= now:
		return ErrExpired
	default:
		// Every condition looks satisfied after all: another caller must
		// have changed the row between the UPDATE and this read (most
		// plausibly, redeemed it themselves in that gap). Report it as
		// already claimed rather than guessing at a cause that no longer
		// applies.
		return ErrAlreadyRedeemed
	}
}

// Revoke marks an invitation as no longer usable, whether or not it has
// already been redeemed or has expired. Revocation is idempotent within a
// single call (repeated calls after the first return ErrNotFound only if
// the token never existed, not because it is already revoked) and never
// itself races with Redeem: both are single, unconditional-on-prior-read
// statements evaluated through RowsAffected.
func Revoke(db *sql.DB, rawToken string) error {
	hash := hashToken(rawToken)
	result, err := db.Exec(`UPDATE invitations SET revoked = 1 WHERE token_hash = ?`, hash)
	if err != nil {
		return fmt.Errorf("invite: revoke: %w", err)
	}
	affected, err := result.RowsAffected()
	if err != nil {
		return fmt.Errorf("invite: revoke: rows affected: %w", err)
	}
	if affected == 0 {
		return ErrNotFound
	}
	return nil
}

// randRead is crypto/rand.Read, indirected through a package variable so
// tests can simulate an exhausted entropy source — a real failure here is
// not something a test can trigger against the genuine source on demand.
var randRead = rand.Read

// randomToken generates a fresh token with tokenBytes of entropy from
// crypto/rand, hex-encoded so it is safe to embed in a URL.
func randomToken() (string, error) {
	buf := make([]byte, tokenBytes)
	if _, err := randRead(buf); err != nil {
		return "", fmt.Errorf("invite: generate token: %w", err)
	}
	return hex.EncodeToString(buf), nil
}

// hashToken derives the value that is actually stored and matched against —
// the plaintext token itself never touches the database.
//
// Looking a token up by this hash via a database equality/index lookup
// (rather than fetching a candidate row by some other key and then
// comparing secret bytes in Go) is the time-constant comparison the concept
// calls for: SHA-256 is a fixed-size digest, matching happens by exact
// equality on that digest, and there is no manual byte-by-byte comparison of
// secret material in this package that a timing side channel could target.
func hashToken(token string) string {
	sum := sha256.Sum256([]byte(token))
	return hex.EncodeToString(sum[:])
}
