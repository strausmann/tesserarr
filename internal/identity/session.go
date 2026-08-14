// Package identity owns everything that proves who is making a request once
// a login has already happened: sessions today, invitations and provider
// identities as the rest of Teil B lands.
package identity

import (
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"time"
)

// sessionTokenBytes is the amount of randomness behind a session token
// before encoding. 32 bytes (256 bits) is the floor the concept sets — well
// beyond what brute force can reach, and matching the entropy the invitation
// tokens already use.
const sessionTokenBytes = 32

// ErrSessionInvalid is returned by Lookup for every reason a session does
// not currently authenticate a request — unknown token, expired token, or a
// token that once existed but was destroyed or rotated away. The reasons are
// deliberately not distinguished in the returned error: telling a caller
// "expired" versus "never existed" would hand an attacker probing tokens a
// free oracle for which guesses are close.
var ErrSessionInvalid = errors.New("session invalid")

// SessionConfig configures a SessionStore. TTL is the lifetime a newly
// created session is granted, measured from the moment it is created.
type SessionConfig struct {
	TTL time.Duration
}

// SessionStore creates, looks up, rotates and destroys sessions backed by the
// sessions table. It never holds a raw token anywhere but the return value
// handed to its caller — every column it writes or reads is the token's hash.
type SessionStore struct {
	db  *sql.DB
	ttl time.Duration
}

// NewSessionStore builds a SessionStore over an already-migrated database
// handle. It does not open or migrate the database itself — that is
// internal/store's job, done once at startup.
func NewSessionStore(db *sql.DB, cfg SessionConfig) *SessionStore {
	return &SessionStore{db: db, ttl: cfg.TTL}
}

// Create starts a new session for userID and returns the token to hand to
// the caller — the only place that value is ever exposed. The database only
// ever receives its hash.
func (s *SessionStore) Create(userID string) (string, error) {
	token := generateToken()

	now := time.Now().UTC()
	expires := now.Add(s.ttl)
	_, err := s.db.Exec(
		`INSERT INTO sessions (token_hash, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)`,
		hashToken(token), userID, formatTime(now), formatTime(expires),
	)
	if err != nil {
		return "", fmt.Errorf("create session: %w", err)
	}
	return token, nil
}

// Lookup resolves token to the user id it belongs to. It rejects a token
// that does not match any stored hash and a token whose session has expired
// — both as ErrSessionInvalid, so a caller cannot tell the two apart from the
// error alone. An expired session is also removed as a side effect: Lookup
// is on every authenticated request, so it catches what a periodic cleanup
// job would otherwise leave valid-looking in the table between runs.
func (s *SessionStore) Lookup(token string) (string, error) {
	hash := hashToken(token)

	var userID, expiresAt string
	err := s.db.QueryRow(
		`SELECT user_id, expires_at FROM sessions WHERE token_hash = ?`, hash,
	).Scan(&userID, &expiresAt)
	if errors.Is(err, sql.ErrNoRows) {
		return "", ErrSessionInvalid
	}
	if err != nil {
		return "", fmt.Errorf("lookup session: %w", err)
	}

	expires, err := time.Parse(time.RFC3339, expiresAt)
	if err != nil {
		return "", fmt.Errorf("lookup session: parse expiry: %w", err)
	}
	if time.Now().UTC().After(expires) {
		// Best effort: whether the delete itself succeeds does not change
		// the verdict — an expired session is invalid either way, and the
		// next Lookup or the cleanup job gets another chance to remove it.
		_, _ = s.db.Exec(`DELETE FROM sessions WHERE token_hash = ?`, hash)
		return "", ErrSessionInvalid
	}

	return userID, nil
}

// Rotate invalidates oldToken and issues a fresh session for userID,
// returning the new token. It is called after every successful login,
// whether or not oldToken currently resolves to anything: the defense it
// implements is against session fixation, where an attacker plants a token
// before the victim authenticates, so the token that existed before login
// must never survive login regardless of what it currently points to.
func (s *SessionStore) Rotate(oldToken, userID string) (string, error) {
	if _, err := s.db.Exec(`DELETE FROM sessions WHERE token_hash = ?`, hashToken(oldToken)); err != nil {
		return "", fmt.Errorf("rotate session: invalidate old token: %w", err)
	}

	newToken, err := s.Create(userID)
	if err != nil {
		return "", fmt.Errorf("rotate session: %w", err)
	}
	return newToken, nil
}

// Destroy invalidates token, e.g. for a logout. It is idempotent: destroying
// a token that is already gone (already expired, already destroyed, never
// existed) is not an error — the caller's goal, "this token no longer
// authenticates anything", already holds.
func (s *SessionStore) Destroy(token string) error {
	if _, err := s.db.Exec(`DELETE FROM sessions WHERE token_hash = ?`, hashToken(token)); err != nil {
		return fmt.Errorf("destroy session: %w", err)
	}
	return nil
}

// generateToken draws sessionTokenBytes of randomness from crypto/rand — the
// only source in the standard library fit for a security token — and encodes
// it URL- and cookie-safely. math/rand or any other non-cryptographic source
// must never appear here: it is predictable, which turns "session token"
// into "guessable session token".
//
// rand.Read's error return is intentionally ignored, not unhandled: as of Go
// 1.24 it is documented to never return an error — a failure of the
// underlying OS entropy source crashes the process irrecoverably instead
// (see https://pkg.go.dev/crypto/rand#Read and https://go.dev/issue/66821).
// There is no error left for this function to hand back, and no way for a
// test to simulate one without taking the whole test binary down with it —
// confirmed by trying exactly that before writing this comment.
func generateToken() string {
	buf := make([]byte, sessionTokenBytes)
	_, _ = rand.Read(buf)
	return base64.RawURLEncoding.EncodeToString(buf)
}

// hashToken turns a raw token into the value the database is allowed to
// hold. Looking a session up by this hash — an exact match against an
// indexed column — is not a place a naive, early-exit byte comparison could
// leak timing information the way comparing two secrets directly can:
// finding a session requires guessing a preimage of a valid hash, not
// winning a byte-by-byte race against a comparison function.
func hashToken(token string) string {
	sum := sha256.Sum256([]byte(token))
	return hex.EncodeToString(sum[:])
}

// formatTime is the one place that decides how a session timestamp is
// stored, so Create and the parse in Lookup can never quietly drift apart on
// format. RFC3339 in UTC: readable, sortable, and immune to the local
// timezone of whichever host happens to run the process.
func formatTime(t time.Time) string {
	return t.UTC().Format(time.RFC3339)
}
