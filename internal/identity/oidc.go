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
	db       *sql.DB
	sessions *SessionStore
	oauth    oauth2.Config
	verifier *oidc.IDTokenVerifier
	attempts *loginAttemptStore
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
		verifier: provider.Verifier(&oidc.Config{ClientID: cfg.ClientID}),
		attempts: newLoginAttemptStore(),
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
		return userID, nil
	case !errors.Is(err, sql.ErrNoRows):
		return "", fmt.Errorf("identity: look up oidc identity: %w", err)
	}

	if inviteToken == "" {
		return "", ErrUnknownIdentityWithoutInvite
	}
	return o.createUserFromInvite(subject, email, inviteToken)
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
// Tesserarr has never seen, binds the confirmed identity to it, and then
// redeems inviteToken against that user — in that order, because
// invite.Redeem's redeemed_by foreign key requires the user row to already
// exist.
//
// If redemption fails, the user (and, via ON DELETE CASCADE, its identity
// row) is removed again: without a successfully redeemed invitation there
// is no authorization for this person to exist as a Tesserarr user at all
// (ADR-0004), so a rejected redemption must never leave one behind.
func (o *OIDCLogin) createUserFromInvite(subject, email, inviteToken string) (string, error) {
	userID := newUserID()

	tx, err := o.db.Begin()
	if err != nil {
		return "", fmt.Errorf("identity: begin user creation: %w", err)
	}
	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := tx.Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		userID, displayName(email, subject), now,
	); err != nil {
		_ = tx.Rollback()
		return "", fmt.Errorf("identity: create user: %w", err)
	}
	if _, err := tx.Exec(
		`INSERT INTO identities (user_id, provider, subject, email) VALUES (?, ?, ?, ?)`,
		userID, oidcProviderName, subject, nullable(email),
	); err != nil {
		_ = tx.Rollback()
		return "", fmt.Errorf("identity: bind oidc identity: %w", err)
	}
	if err := tx.Commit(); err != nil {
		return "", fmt.Errorf("identity: commit user creation: %w", err)
	}

	if err := invite.Redeem(o.db, inviteToken, userID); err != nil {
		// Best effort, and deliberately not itself the returned error: if
		// this cleanup fails, an orphaned unauthorized user is a bug to
		// find and fix, not a reason to hide why the login was actually
		// rejected behind a cleanup error instead. The invite package's
		// specific reason is wrapped alongside ErrUnknownIdentityWithoutInvite
		// (Go 1.20+ supports more than one %w) purely for logs and
		// errors.Is-based diagnostics — it changes nothing about what an
		// eventual HTTP layer exposes externally, since that layer only
		// ever sees "Callback returned a non-nil error", not which one.
		_, _ = o.db.Exec(`DELETE FROM users WHERE id = ?`, userID)
		return "", fmt.Errorf("%w (redeeming invite: %w)", ErrUnknownIdentityWithoutInvite, err)
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
