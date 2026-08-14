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
// API tokens carry an OPTIONAL expiry — Create's ttl parameter, ttl <= 0
// meaning "never expires". That default matters: Prowlarr, Sonarr and
// Radarr are configured with a token once and then rely on it indefinitely
// (ADR-0007) — forcing every token to expire would mean the user
// re-pasting a fresh value into three other services' configuration on
// some schedule, for no security benefit an outright Revoke does not
// already provide on demand. A caller that DOES want a token to lapse on
// its own (see docs/superpowers/specs/2026-08-14-tesserarr-forward-auth-design.md,
// "API-Token: Ablauf und Verwaltung") opts in with a positive ttl.
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
	ExpiresAt  *time.Time // nil if the token never expires.
}

// Create mints a new API token for userID under label and returns the
// token to hand to the caller — the only place that value is ever exposed
// — together with the row's own metadata (Token), so a caller (see
// internal/api's token-management endpoint) can answer "here is what was
// just created" without a second round trip. ttl <= 0 means "never
// expires" — the only behavior this method had before token expiry
// existed.
func (s *Store) Create(userID, label string, ttl time.Duration) (string, Token, error) {
	token := generateToken()
	createdAt := time.Now().UTC()
	var expiresAt *time.Time
	if ttl > 0 {
		t := createdAt.Add(ttl)
		expiresAt = &t
	}

	if err := insertToken(s.db, token, userID, label, createdAt, expiresAt); err != nil {
		return "", Token{}, err
	}
	return token, Token{Hash: hashToken(token), Label: label, CreatedAt: createdAt, ExpiresAt: expiresAt}, nil
}

// insertToken writes a new api_tokens row directly, bypassing Create's
// contract that ttl <= 0 means "never expires" — split out so tests can
// build a fixture Create cannot: an already-expired token. Mirrors
// invite.insertInvitation (internal/invite/invite.go) for the identical
// rationale.
func insertToken(db *sql.DB, token, userID, label string, createdAt time.Time, expiresAt *time.Time) error {
	var expires any
	if expiresAt != nil {
		expires = formatTime(*expiresAt)
	}
	_, err := db.Exec(
		`INSERT INTO api_tokens (token_hash, user_id, label, created_at, last_used_at, expires_at) VALUES (?, ?, ?, ?, NULL, ?)`,
		hashToken(token), userID, label, formatTime(createdAt), expires,
	)
	if err != nil {
		return fmt.Errorf("create api token: %w", err)
	}
	return nil
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
	var expiresAt sql.NullString
	err := s.db.QueryRow(
		`SELECT user_id, expires_at FROM api_tokens WHERE token_hash = ?`, hash,
	).Scan(&userID, &expiresAt)
	if errors.Is(err, sql.ErrNoRows) {
		return "", ErrTokenInvalid
	}
	if err != nil {
		return "", fmt.Errorf("lookup api token: %w", err)
	}

	// An expired token must be indistinguishable from a revoked or unknown
	// one — see docs/superpowers/specs/2026-08-14-tesserarr-forward-auth-design.md,
	// "API-Token: Ablauf und Verwaltung": returning a distinct error here
	// would let a caller learn "this hash used to be valid" from the
	// response shape alone.
	if expiresAt.Valid {
		expires, err := time.Parse(time.RFC3339, expiresAt.String)
		if err != nil {
			return "", fmt.Errorf("lookup api token: parse expiry: %w", err)
		}
		if time.Now().UTC().After(expires) {
			return "", ErrTokenInvalid
		}
	}

	_, _ = s.db.Exec(`UPDATE api_tokens SET last_used_at = ? WHERE token_hash = ?`, formatTime(time.Now()), hash)

	return userID, nil
}

// Revoke invalidates the token whose hash is hash — but ONLY if it belongs
// to userID. A hash for a token that exists but belongs to someone else is
// treated exactly like an unknown hash: zero rows affected, no error — see
// the Token.Hash doc comment for why handing the hash back is safe, and
// the package comment for why "idempotent, unknown is fine" already covers
// this without a separate ownership error.
func (s *Store) Revoke(userID, hash string) error {
	if _, err := s.db.Exec(`DELETE FROM api_tokens WHERE token_hash = ? AND user_id = ?`, hash, userID); err != nil {
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
		`SELECT token_hash, label, created_at, last_used_at, expires_at FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC`,
		userID,
	)
	if err != nil {
		return nil, fmt.Errorf("list api tokens: %w", err)
	}
	defer func() { _ = rows.Close() }()

	var tokens []Token
	for rows.Next() {
		var hash, label, createdAt string
		var lastUsedAt, expiresAt sql.NullString
		if err := rows.Scan(&hash, &label, &createdAt, &lastUsedAt, &expiresAt); err != nil {
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
		if expiresAt.Valid {
			expires, err := time.Parse(time.RFC3339, expiresAt.String)
			if err != nil {
				return nil, fmt.Errorf("list api tokens: parse expires_at: %w", err)
			}
			tok.ExpiresAt = &expires
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
