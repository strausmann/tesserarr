package identity

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/coreos/go-oidc/v3/oidc"
	"golang.org/x/oauth2"
	"modernc.org/sqlite"
	sqlite3 "modernc.org/sqlite/lib"

	"github.com/strausmann/tesserarr/internal/config"
	"github.com/strausmann/tesserarr/internal/invite"
)

// oidcProviderName is the value stored in identities.provider for every
// identity resolved through this file's generic OIDC login. Tesserarr
// supports exactly one configured OIDC provider at a time (see OIDCConfig)
// — the constant name, not the issuer URL, is what distinguishes an OIDC
// identity from a Plex identity or any other login method added later
// (ADR-0004 point 5: multiple identities per user are expected, one row per
// provider kind).
const oidcProviderName = "oidc"

// stateBytes is the amount of randomness behind the OIDC "state" parameter
// — matching the 256-bit floor session.go and invite.go already use for
// values defending against guessing.
const stateBytes = 32

// userIDBytes is the amount of randomness behind a freshly minted user id.
const userIDBytes = 16

// OIDCConfig configures the single generic OpenID Connect provider this
// deployment authenticates against.
type OIDCConfig struct {
	// IssuerURL identifies the provider and is discovered per OpenID
	// Connect Discovery
	// (https://openid.net/specs/openid-connect-discovery-1_0.html):
	// NewOIDCLogin fetches IssuerURL + "/.well-known/openid-configuration"
	// once and learns every other endpoint (authorization, token, keys)
	// from that single document, instead of the deployer configuring each
	// one by hand.
	IssuerURL string
	// ClientID identifies Tesserarr to the provider.
	ClientID string
	// ClientSecret authenticates Tesserarr to the provider's token
	// endpoint. Typed as config.Secret, not string, so it redacts itself
	// wherever it ends up printed, logged or serialized — see
	// internal/config/secret.go. Reveal() is called exactly once, right
	// where NewOIDCLogin hands it to the oauth2 client that actually needs
	// it.
	ClientSecret config.Secret
	// RedirectURL is where the provider sends the browser back after
	// login. Must exactly match what is registered with the provider.
	RedirectURL string
	// Scopes requested from the provider. Defaults to
	// {openid, profile, email} when empty — the minimum OpenID Connect
	// requires, plus the claim resolveUser actually reads (email, for a
	// readable display name on first login; the identity match itself
	// always uses the subject, never the email).
	Scopes []string
	// DefaultRole is written into a brand-new user's role column at
	// creation — see internal/identity/role.go and
	// docs/superpowers/specs/2026-08-14-tesserarr-forward-auth-design.md,
	// "Rollen". Applies to every new user regardless of login mechanism:
	// the role system is not forward-auth-specific.
	DefaultRole string
}

// ErrLoginStateInvalid is returned by Callback for every reason its state
// parameter does not authenticate a login this OIDCLogin started: unknown,
// already consumed by an earlier callback (replay), or expired. As with
// ErrSessionInvalid (session.go), the reasons are deliberately not told
// apart — a forged or replayed callback probing state values should not
// learn which guesses came closer.
//
// Checked BEFORE the authorization code is ever exchanged: exchanging
// first and validating state second would let a forged or replayed
// callback burn a real authorization code against the provider before
// being rejected for the state mismatch anyway.
var ErrLoginStateInvalid = errors.New("identity: oidc login state invalid, already used, or expired")

// ErrUnknownIdentityWithoutInvite is returned by Callback when the provider
// confirms a subject Tesserarr has never seen, and no valid, unredeemed
// invitation accompanies the login. Per ADR-0004 this is the only door in:
// a successful provider login by itself never grants access — identity
// only ever answers "who is this", never "should this person get in".
var ErrUnknownIdentityWithoutInvite = errors.New("identity: unknown oidc identity without a valid invitation")

// OIDCLogin drives Tesserarr's side of a single generic OIDC provider's
// PKCE-protected authorization code flow end to end: BeginLogin issues the
// redirect, Callback completes it, resolves the confirmed identity to a
// Tesserarr user — minting one via a redeemed invitation for a first-time
// subject (ADR-0004) — and hands back a freshly created, rotated session
// token (ADR-0007: the browser gets a session, never a provider token).
type OIDCLogin struct {
	db          *sql.DB
	sessions    *SessionStore
	oauth       oauth2.Config
	verifier    *oidc.IDTokenVerifier
	attempts    *loginAttemptStore
	defaultRole string
}

// NewOIDCLogin discovers cfg.IssuerURL's endpoints and builds an OIDCLogin
// ready to serve BeginLogin and Callback. It performs one network round
// trip — fetching the discovery document — before returning, so it belongs
// at startup, not per request.
func NewOIDCLogin(ctx context.Context, db *sql.DB, sessions *SessionStore, cfg OIDCConfig) (*OIDCLogin, error) {
	provider, err := oidc.NewProvider(ctx, cfg.IssuerURL)
	if err != nil {
		return nil, fmt.Errorf("identity: discover oidc provider %q: %w", cfg.IssuerURL, err)
	}

	scopes := cfg.Scopes
	if len(scopes) == 0 {
		scopes = []string{oidc.ScopeOpenID, oidc.ScopeProfile, oidc.ScopeEmail}
	}

	return &OIDCLogin{
		db:       db,
		sessions: sessions,
		oauth: oauth2.Config{
			ClientID:     cfg.ClientID,
			ClientSecret: cfg.ClientSecret.Reveal(),
			RedirectURL:  cfg.RedirectURL,
			Endpoint:     provider.Endpoint(),
			Scopes:       scopes,
		},
		verifier:    provider.Verifier(&oidc.Config{ClientID: cfg.ClientID}),
		attempts:    newLoginAttemptStore(),
		defaultRole: cfg.DefaultRole,
	}, nil
}

// BeginLogin starts a new login attempt and returns the URL to redirect the
// browser to.
//
// inviteToken is the invitation this login is meant to redeem — pass "" for
// an ordinary login by someone who already has an identity on file. It
// travels with the attempt (see store.go) so Callback has it to check
// against ADR-0004 once the provider has confirmed who is logging in.
//
// state and the PKCE verifier are generated here and stored server-side —
// never in a cookie (see loginAttemptStore's package comment in store.go
// for why).
func (o *OIDCLogin) BeginLogin(inviteToken string) string {
	state := randomURLSafe(stateBytes)
	verifier := oauth2.GenerateVerifier()

	o.attempts.put(state, loginAttempt{
		verifier:    verifier,
		inviteToken: inviteToken,
	}, loginAttemptTTL)

	return o.oauth.AuthCodeURL(state, oauth2.S256ChallengeOption(verifier))
}

// Callback completes a login started by BeginLogin.
//
// gotState and code come straight off the callback request's query
// parameters — Callback does not read HTTP itself, so wiring it into the
// API package's session-checked routes (ADR-0007) only means passing three
// strings through. previousSessionToken is whatever session cookie the
// browser presented, if any ("" if none); it is rotated away regardless of
// whether it currently resolves to anything, per SessionStore.Rotate's
// session-fixation defense — "erzeugen und rotieren" (create and rotate)
// is one call, not two, so a first-time login and a returning login look
// identical from here on.
func (o *OIDCLogin) Callback(ctx context.Context, gotState, code, previousSessionToken string) (sessionToken string, err error) {
	attempt, ok := o.attempts.take(gotState)
	if !ok {
		return "", ErrLoginStateInvalid
	}

	oauthToken, err := o.oauth.Exchange(ctx, code, oauth2.VerifierOption(attempt.verifier))
	if err != nil {
		return "", fmt.Errorf("identity: exchange oidc authorization code: %w", err)
	}

	rawIDToken, ok := oauthToken.Extra("id_token").(string)
	if !ok {
		return "", errors.New("identity: oidc token response carried no id_token")
	}
	idToken, err := o.verifier.Verify(ctx, rawIDToken)
	if err != nil {
		return "", fmt.Errorf("identity: verify oidc id token: %w", err)
	}

	var claims struct {
		Email string `json:"email"`
	}
	if err := idToken.Claims(&claims); err != nil {
		return "", fmt.Errorf("identity: parse oidc claims: %w", err)
	}

	userID, err := o.resolveUser(idToken.Subject, claims.Email, attempt.inviteToken)
	if err != nil {
		return "", err
	}

	newToken, err := o.sessions.Rotate(previousSessionToken, userID)
	if err != nil {
		return "", fmt.Errorf("identity: start session: %w", err)
	}
	return newToken, nil
}

// resolveUser maps a confirmed provider identity (subject, already
// implicitly scoped to oidcProviderName) to a Tesserarr user id.
//
// An identity already bound to a user — a returning login — resolves
// directly, ignoring inviteToken entirely: a returning user must not be
// made to need a fresh invitation just to log back in.
//
// An identity Tesserarr has never seen resolves only through a valid,
// unredeemed invitation (ADR-0004: identity answers "who", invite answers
// "may they in"). An empty inviteToken, and every reason invite.Redeem can
// reject one (not found, expired, revoked, already redeemed), all surface
// as the same ErrUnknownIdentityWithoutInvite — not told apart, for the
// same reason ErrLoginStateInvalid isn't: a login endpoint must not become
// an oracle for probing which invitation tokens are real.
func (o *OIDCLogin) resolveUser(subject, email, inviteToken string) (string, error) {
	userID, err := lookupIdentity(o.db, oidcProviderName, subject)
	switch {
	case err == nil:
		if updateErr := o.updateRole(userID); updateErr != nil {
			return "", updateErr
		}
		return userID, nil
	case !errors.Is(err, sql.ErrNoRows):
		return "", fmt.Errorf("identity: look up oidc identity: %w", err)
	}

	if inviteToken == "" {
		return "", ErrUnknownIdentityWithoutInvite
	}
	return o.createUserFromInvite(subject, email, inviteToken)
}

// updateRole recomputes and stores users.role — the CURRENTLY EFFECTIVE
// role — for a RETURNING OIDC login, mirroring what ForwardAuthLogin's
// updateProfile does on every forward-auth entry (forwardauth.go).
//
// Before this existed, the "case err == nil" branch above returned userID
// without touching role at all: an operator promoting OR demoting a user
// who signs in exclusively via OIDC (never forward-auth) had no login path
// left that would ever apply the change. PromoteUserRole only ever writes
// role_local (admin.go); role_local governs the EFFECTIVE role only
// through this kind of recomputation, and forward-auth's updateProfile was
// the only place doing it — a user with just an OIDC identity has no
// forward-auth entry to trigger that.
//
// OIDC carries no role header of its own (unlike forward-auth's
// RoleHeaderRaw) — see the design doc, "Die Rolle wird bei jedem Eintritt
// neu gelesen" applies here with an always-empty header. Passing ""
// for ResolveRoleNames's headerRaw, and nil for its known-roles map
// (unreachable when headerRaw is "", see that function's own branching),
// always falls straight to role_local — or defaultRole, if no operator has
// ever assigned one — exactly forward-auth's own header-less-entry
// behavior (role.go, ResolveRoleNames's case 3).
func (o *OIDCLogin) updateRole(userID string) error {
	roleLocal, err := currentLocalRole(o.db, userID)
	if err != nil {
		return err
	}
	roleNames := ResolveRoleNames("", nil, o.defaultRole, roleLocal)
	if _, err := o.db.Exec(`UPDATE users SET role = ? WHERE id = ?`, JoinRoleNames(roleNames), userID); err != nil {
		return fmt.Errorf("identity: update oidc role: %w", err)
	}
	return nil
}

// lookupIdentity resolves an already-bound provider identity to its user
// id. sql.ErrNoRows means exactly that this subject has never logged in
// before through this provider — the normal, expected shape of a
// first-time login, not a failure.
func lookupIdentity(db *sql.DB, provider, subject string) (string, error) {
	var userID string
	err := db.QueryRow(
		`SELECT user_id FROM identities WHERE provider = ? AND subject = ?`,
		provider, subject,
	).Scan(&userID)
	return userID, err
}

// createUserFromInvite mints a brand-new user for a provider subject
// Tesserarr has never seen, binds the confirmed identity to it, and redeems
// inviteToken against that user — all three in the SAME transaction, so a
// rejected redemption rolls back the whole thing atomically instead of
// relying on a separate, best-effort compensating cleanup.
//
// This used to be two steps: commit the INSERTs, then call invite.Redeem
// separately, and on failure attempt a single, unretried
// `DELETE FROM users` whose own error was discarded. Security audit run-1
// ("Invite-gate bypass: an untransacted compensating DELETE can leave a
// permanent, never-actually-redeemed account if it fails to run") found
// the gap: if that DELETE never ran (a process crash landing in the
// narrow window between the commit and the DELETE) or itself failed (a
// transient DB error), the previously committed user row survived
// permanently — an account that existed without ever actually redeeming an
// invitation, a direct violation of ADR-0004's "invitation is the only
// door in" model that resolveUser's known-subject shortcut would then
// honor on every future login for the same subject, with no further
// check. invite.Redeem's underlying operation is already a single atomic
// UPDATE (see its own comment); running it inside this transaction, before
// commit, needs no separate cleanup at all — a rejected redemption is
// undone by the same tx.Rollback() that already handles the INSERT
// failures below.
//
// It can still LOSE a race it did not start — the exact same shape
// ForwardAuthLogin.createUserFromInvite (forwardauth.go) documents for
// its own identical INSERT: resolveUser only reaches here after its own
// lookupIdentity already reported sql.ErrNoRows, but that check and this
// function's own INSERT are not the same transaction — two callers for
// the SAME subject (a doubled click, or the same OIDC callback URL opened
// in two tabs) can both see "unknown" before either commits. Only one
// INSERT into identities can win identities' PRIMARY KEY (provider,
// subject) (migrations/0001_initial.sql); before this fix, the other
// surfaced that raw constraint violation as Callback's own error, failing
// a login that — from the visitor's side — looks identical to any other.
// See resolveExistingIdentity for what the loser does instead.
func (o *OIDCLogin) createUserFromInvite(subject, email, inviteToken string) (string, error) {
	userID := newUserID()

	tx, err := o.db.Begin()
	if err != nil {
		return "", fmt.Errorf("identity: begin user creation: %w", err)
	}
	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := tx.Exec(
		`INSERT INTO users (id, display_name, created_at, email, role) VALUES (?, ?, ?, ?, ?)`,
		userID, displayName(email, subject), now, nullable(email), o.defaultRole,
	); err != nil {
		_ = tx.Rollback()
		return "", fmt.Errorf("identity: create user: %w", err)
	}
	if _, err := tx.Exec(
		`INSERT INTO identities (user_id, provider, subject, email) VALUES (?, ?, ?, ?)`,
		userID, oidcProviderName, subject, nullable(email),
	); err != nil {
		_ = tx.Rollback()
		// A typed check, not a string match on err.Error() — the same
		// pattern RemapForwardAuthSubject (admin.go) and
		// ForwardAuthLogin.createUserFromInvite (forwardauth.go) already
		// established for this exact constraint, confirmed empirically
		// there that modernc.org/sqlite wraps it as *sqlite.Error with
		// Code() == SQLITE_CONSTRAINT_PRIMARYKEY (1555).
		var sqliteErr *sqlite.Error
		if errors.As(err, &sqliteErr) && sqliteErr.Code() == sqlite3.SQLITE_CONSTRAINT_PRIMARYKEY {
			// Lost the race this function's own comment describes. SQLite
			// serializes writers (WAL, one writer transaction at a time):
			// this INSERT could only fail on an ALREADY-COMMITTED row for
			// the same (provider, subject), never one still in flight —
			// so the winner's row is guaranteed visible by now, and this
			// never spins or retries against a row that might vanish
			// again.
			return o.resolveExistingIdentity(subject)
		}
		return "", fmt.Errorf("identity: bind oidc identity: %w", err)
	}
	if err := invite.Redeem(tx, inviteToken, userID); err != nil {
		_ = tx.Rollback()
		// The invite package's specific reason is wrapped alongside
		// ErrUnknownIdentityWithoutInvite (Go 1.20+ supports more than one
		// %w) purely for logs and errors.Is-based diagnostics — it changes
		// nothing about what an eventual HTTP layer exposes externally,
		// since that layer only ever sees "Callback returned a non-nil
		// error", not which one.
		return "", fmt.Errorf("%w (redeeming invite: %w)", ErrUnknownIdentityWithoutInvite, err)
	}
	if err := tx.Commit(); err != nil {
		return "", fmt.Errorf("identity: commit user creation: %w", err)
	}
	return userID, nil
}

// resolveExistingIdentity is what a caller that LOST the create race in
// createUserFromInvite falls back to — the OIDC counterpart of
// ForwardAuthLogin.resolveExistingIdentity (forwardauth.go), same
// reasoning: look up the identity that raced ahead of it — by the time the
// constraint violation surfaced, guaranteed committed (see
// createUserFromInvite's own comment) — and apply the same updateRole step
// resolveUser's own "existing identity" branch already runs for any
// ordinary returning login. From the visitor's side, losing this race now
// looks exactly like logging into an account that already existed, which
// — a moment earlier — it now does.
//
// inviteToken is deliberately never touched here: the loser's own
// transaction rolled back before ever reaching invite.Redeem (the INSERT
// into identities that failed happens BEFORE that call in
// createUserFromInvite), so the invitation this caller was handed stays
// unredeemed — available for whoever the provider sends it to next,
// rather than being silently spent by a request that never actually
// needed it.
func (o *OIDCLogin) resolveExistingIdentity(subject string) (string, error) {
	userID, err := lookupIdentity(o.db, oidcProviderName, subject)
	if err != nil {
		return "", fmt.Errorf("identity: look up oidc identity after losing create race: %w", err)
	}
	if err := o.updateRole(userID); err != nil {
		return "", err
	}
	return userID, nil
}

// displayName picks what to show for a brand-new user before they have set
// anything themselves: the email's local part if there is an email, else
// the bare provider subject — always something, never empty, since
// users.display_name is NOT NULL.
func displayName(email, subject string) string {
	if email != "" {
		if at := strings.IndexByte(email, '@'); at > 0 {
			return email[:at]
		}
		return email
	}
	return subject
}

// nullable turns an empty string into a genuine SQL NULL rather than
// storing an empty string for "no email" — identities.email is nullable
// specifically to make that distinction possible.
func nullable(s string) any {
	if s == "" {
		return nil
	}
	return s
}

// randomURLSafe returns n bytes of crypto/rand randomness, URL- and
// cookie-safely encoded. Mirrors session.go's generateToken: rand.Read's
// error is intentionally ignored, not unhandled — as of Go 1.24 it is
// documented to never return an error (see generateToken's comment in
// session.go for the full reasoning, which applies here verbatim).
func randomURLSafe(n int) string {
	buf := make([]byte, n)
	_, _ = rand.Read(buf)
	return base64.RawURLEncoding.EncodeToString(buf)
}

// newUserID mints an opaque, unguessable user id. Hex rather than the
// base64url encoding session.go and invite.go use for their tokens, only
// because a primary key that never needs to appear in a URL has no reason
// to prefer the shorter encoding.
func newUserID() string {
	buf := make([]byte, userIDBytes)
	_, _ = rand.Read(buf)
	return "user_" + hex.EncodeToString(buf)
}
