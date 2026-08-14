// Package apitoken owns the personal API tokens ADR-0007 promises for
// Prowlarr, Sonarr and Radarr: long-lived credentials a user mints for
// themselves, independent of any browser session.
//
// It is a store separate from identity.SessionStore on purpose, not merely a
// second table reached through a shared lookup. requireToken in internal/api
// resolves a bearer value against THIS store and nothing else; requireSession
// resolves a cookie against identity.SessionStore and nothing else. A session
// token hashed against api_tokens matches nothing, and an API token hashed
// against sessions matches nothing either — that mismatch is not an edge
// case to guard against, it is the point. It is the second, independent
// barrier the concept's "Anmeldung und Sitzungen" section requires: no token
// gets into the "Verwaltung" view even if a future implementation bug let a
// session-checked route fall through to the token check, or vice versa,
// because the two credential kinds are not merely checked through different
// code paths (see ADR-0007) but resolved against different storage. A shared
// store, however carefully the two checks above it were kept apart, would
// leave that second barrier absent.
package apitoken

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

// tokenBytes is the amount of randomness behind an API token before
// encoding — the same floor identity.SessionStore uses for session tokens,
// and for the same reason: 32 bytes (256 bits) is well beyond what brute
// force can reach.
const tokenBytes = 32

// ErrTokenInvalid is returned by Lookup for every reason a token does not
// currently authenticate a request — unknown token or a token that was
// revoked. Unlike identity.ErrSessionInvalid there is no expiry to conflate
// with revocation (API tokens carry no TTL — see the Store doc comment), but
// the same principle applies: the reason is deliberately not distinguished
// in the error, so a caller cannot turn it into an oracle for which guesses
// are close.
var ErrTokenInvalid = errors.New("api token invalid")

// Store creates, looks up, lists and revokes API tokens backed by the
// api_tokens table. Like identity.SessionStore, it never holds a raw token
// anywhere but the return value Create hands back — every column it writes
// or reads is the token's hash.
//
// API tokens carry no expiry, unlike sessions. A session is a browser's
// standing-in for an interactive login and is expected to lapse; a personal
// API token is the credential Prowlarr, Sonarr and Radarr are configured
// with once and then rely on indefinitely (ADR-0007) — forcing it to expire
// would mean the user re-pasting a fresh value into three other services'
// configuration on some schedule, for no security benefit an outright
// Revoke does not already provide on demand.
type Store struct {
	db *sql.DB
}

// New builds a Store over an already-migrated database handle. It does not
// open or migrate the database itself — that is internal/store's job, done
// once at startup.
func New(db *sql.DB) *Store {
	return &Store{db: db}
}

// Token is one row of api_tokens as List returns it: enough for a user to
// tell their tokens apart and decide which to revoke, and deliberately
// nothing that authenticates anything on its own — see the Hash field
// comment for why that includes Hash itself.
type Token struct {
	// Hash is exactly the value stored in the token_hash column. Showing it
	// and accepting it back from Revoke is safe precisely because it is a
	// SHA-256 digest: unlike the raw token, knowing it does not let anyone
	// reconstruct a value that would pass Lookup. Lookup hashes whatever it
	// is given before comparing, so handing this digest back in as if it
	// were a token hashes it a second time and matches nothing.
	Hash       string
	Label      string
	CreatedAt  time.Time
	LastUsedAt *time.Time // nil if the token has never been used.
}

// Create mints a new API token for userID under label and returns the token
// to hand to the caller — the only place that value is ever exposed. The
// database only ever receives its hash, exactly like
// identity.SessionStore.Create.
func (s *Store) Create(userID, label string) (string, error) {
	token := generateToken()

	_, err := s.db.Exec(
		`INSERT INTO api_tokens (token_hash, user_id, label, created_at, last_used_at) VALUES (?, ?, ?, ?, NULL)`,
		hashToken(token), userID, label, formatTime(time.Now()),
	)
	if err != nil {
		return "", fmt.Errorf("create api token: %w", err)
	}
	return token, nil
}

// Lookup resolves token to the user id it belongs to and, as a deliberate
// side effect, records that it was just used.
//
// Why that write happens on every call, not only from some dedicated
// "mark used" endpoint: the auth middleware calling Lookup is the only place
// that ever actually knows a token was used, so a separate trigger would
// either reinvent this same UPDATE from a less accurate signal (e.g. a
// polling job) or simply never fire. The value it buys is real: List exposes
// LastUsedAt precisely so a user can tell a token Sonarr still polls hourly
// from one nobody has touched in a year and is safe to Revoke.
//
// Why it is done unconditionally rather than throttled (e.g. "at most once
// an hour"): throttling would need to read the existing last_used_at before
// deciding whether to write, trading one unconditional write for a
// conditional read-then-maybe-write — more round trips, not fewer, for a
// self-hosted, single-tenant app whose token-path traffic is Prowlarr,
// Sonarr and Radarr polling on the order of minutes, not requests per
// second. SQLite's WAL mode and the busy_timeout already configured in
// internal/store absorb that write without contention at this scale.
//
// Why the write is best-effort (its error is ignored) rather than folded
// into the verdict: it is exactly the same idiom identity.SessionStore.Lookup
// already uses for its expired-session delete — whether the write below
// succeeds does not change the answer already reached by the SELECT above,
// and a transient failure to record "last used" must never turn a valid
// token into a failed authentication.
func (s *Store) Lookup(token string) (string, error) {
	hash := hashToken(token)

	var userID string
	err := s.db.QueryRow(
		`SELECT user_id FROM api_tokens WHERE token_hash = ?`, hash,
	).Scan(&userID)
	if errors.Is(err, sql.ErrNoRows) {
		return "", ErrTokenInvalid
	}
	if err != nil {
		return "", fmt.Errorf("lookup api token: %w", err)
	}

	_, _ = s.db.Exec(`UPDATE api_tokens SET last_used_at = ? WHERE token_hash = ?`, formatTime(time.Now()), hash)

	return userID, nil
}

// Revoke invalidates the token whose hash is hash — see the Token.Hash doc
// comment for why accepting the hash back from a caller is safe. It is
// idempotent, for the same reason identity.SessionStore.Destroy is: revoking
// a token that is already gone (already revoked, never existed) is not an
// error — the caller's goal, "this token no longer authenticates anything",
// already holds.
func (s *Store) Revoke(hash string) error {
	if _, err := s.db.Exec(`DELETE FROM api_tokens WHERE token_hash = ?`, hash); err != nil {
		return fmt.Errorf("revoke api token: %w", err)
	}
	return nil
}

// List returns every token belonging to userID, most recently created
// first, without ever including the raw token — there is nothing to
// include; Create is the only place it ever existed outside whatever the
// caller who received it did with it.
func (s *Store) List(userID string) ([]Token, error) {
	rows, err := s.db.Query(
		`SELECT token_hash, label, created_at, last_used_at FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC`,
		userID,
	)
	if err != nil {
		return nil, fmt.Errorf("list api tokens: %w", err)
	}
	defer func() { _ = rows.Close() }()

	var tokens []Token
	for rows.Next() {
		var hash, label, createdAt string
		var lastUsedAt sql.NullString
		if err := rows.Scan(&hash, &label, &createdAt, &lastUsedAt); err != nil {
			return nil, fmt.Errorf("scan api token: %w", err)
		}

		created, err := time.Parse(time.RFC3339, createdAt)
		if err != nil {
			return nil, fmt.Errorf("list api tokens: parse created_at: %w", err)
		}

		tok := Token{Hash: hash, Label: label, CreatedAt: created}
		if lastUsedAt.Valid {
			used, err := time.Parse(time.RFC3339, lastUsedAt.String)
			if err != nil {
				return nil, fmt.Errorf("list api tokens: parse last_used_at: %w", err)
			}
			tok.LastUsedAt = &used
		}
		tokens = append(tokens, tok)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("iterate api tokens: %w", err)
	}
	return tokens, nil
}

// generateToken draws tokenBytes of randomness from crypto/rand and encodes
// it URL- and header-safely — see identity.SessionStore's generateToken for
// why crypto/rand is non-negotiable here and why its error return is
// intentionally ignored rather than unhandled (as of Go 1.24 it never
// returns one; a failed read crashes the process instead).
func generateToken() string {
	buf := make([]byte, tokenBytes)
	_, _ = rand.Read(buf)
	return base64.RawURLEncoding.EncodeToString(buf)
}

// hashToken turns a raw token into the value the database is allowed to
// hold — see identity.SessionStore's hashToken for why an exact-match lookup
// by this hash does not reopen the timing side channel a direct
// secret-to-secret comparison would.
func hashToken(token string) string {
	sum := sha256.Sum256([]byte(token))
	return hex.EncodeToString(sum[:])
}

// formatTime is the one place that decides how an api_tokens timestamp is
// stored, so Create, Lookup's last_used_at write and List's parsing can
// never quietly drift apart on format. RFC3339 in UTC, matching
// identity.SessionStore's formatTime.
func formatTime(t time.Time) string {
	return t.UTC().Format(time.RFC3339)
}
