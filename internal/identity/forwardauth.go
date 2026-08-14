package identity

import (
	"database/sql"
	"errors"
	"fmt"
	"time"

	"github.com/strausmann/tesserarr/internal/config"
	"github.com/strausmann/tesserarr/internal/invite"
)

// forwardAuthProviderName is the value stored in identities.provider for
// every identity resolved through a forward-auth entry — the counterpart
// to oidcProviderName in oidc.go. Kept separate so the same subject value
// arriving through OIDC and through a forward-auth guard never collides:
// they are, deliberately, two different identities until an operator
// merges them (there is no automatic merge — see RemapForwardAuthSubject
// in admin.go for the operator-driven path).
const forwardAuthProviderName = "forward-auth"

// ForwardAuthLogin resolves an already-trust-checked forward-auth request
// to a session, mirroring OIDCLogin's shape but with no external round
// trip: the caller (internal/api) has already verified the request's
// source IP and shared secret before this type ever sees it — see
// docs/superpowers/specs/2026-08-14-tesserarr-forward-auth-design.md,
// "Ablauf", steps 1-2.
type ForwardAuthLogin struct {
	db          *sql.DB
	sessions    *SessionStore
	roles       config.RoleMap
	defaultRole string
}

// NewForwardAuthLogin builds a ForwardAuthLogin over an already-migrated
// database handle and an existing SessionStore — the same session store
// OIDCLogin shares, so a forward-auth session and an OIDC session are
// indistinguishable to requireSession (internal/api/middleware.go).
func NewForwardAuthLogin(db *sql.DB, sessions *SessionStore, roles config.RoleMap, defaultRole string) *ForwardAuthLogin {
	return &ForwardAuthLogin{db: db, sessions: sessions, roles: roles, defaultRole: defaultRole}
}

// ForwardAuthClaims is what internal/api extracts from the guard's headers
// before calling Authenticate — plain strings, no HTTP types, so this
// package stays as free of net/http as oidc.go already is.
type ForwardAuthClaims struct {
	// Subject is the value of the configured subject header — the "who is
	// this" key ADR-0004's identities table matches on.
	Subject string
	// Username, Name and Email are optional display attributes — see the
	// design doc's "Name und E-Mail" section for why blank values are left
	// alone rather than overwriting something a previous entry already set.
	Username string
	Name     string
	Email    string
	// RoleHeaderRaw is the UNSPLIT value of the configured role header,
	// exactly as the request carried it, "" if the header was absent or
	// empty. ResolveRoleNames (role.go) is what actually parses it.
	RoleHeaderRaw string
}

// ErrForwardAuthSubjectEmpty is returned by Authenticate for an empty
// Subject claim — internal/api is expected to have already rejected this
// before ever calling Authenticate, but a defense here costs nothing.
var ErrForwardAuthSubjectEmpty = errors.New("identity: forward-auth subject header empty")

// Authenticate resolves claims to a Tesserarr user — an existing identity
// directly, a brand-new one only via inviteToken (ADR-0004, the identical
// rule OIDCLogin.resolveUser enforces) — updates that user's display name,
// email and role from claims, and returns a freshly rotated session token.
// previousSessionToken is whatever session cookie the request already
// carried, if any — rotated away regardless, for the same session-fixation
// reason OIDCLogin.Callback rotates it (see SessionStore.Rotate).
func (f *ForwardAuthLogin) Authenticate(claims ForwardAuthClaims, inviteToken, previousSessionToken string) (string, error) {
	if claims.Subject == "" {
		return "", ErrForwardAuthSubjectEmpty
	}

	userID, err := lookupIdentity(f.db, forwardAuthProviderName, claims.Subject)
	switch {
	case err == nil:
		if updateErr := f.updateProfile(userID, claims); updateErr != nil {
			return "", updateErr
		}
	case errors.Is(err, sql.ErrNoRows):
		// This inviteToken == "" check is DELIBERATELY redundant with
		// createUserFromInvite's own invite.Redeem call a few lines down:
		// Redeem("", userID) hashes the empty string, finds no matching
		// row, and returns the same ErrUnknownIdentityWithoutInvite-wrapped
		// rejection on its own — see
		// TestAuthenticateRejectsUnknownSubjectWithoutInvite's comment for
		// what that means for what the test actually proves. Kept anyway
		// for two reasons: it mirrors OIDCLogin.resolveUser's identical
		// early check (oidc.go), and it avoids opening a transaction
		// (tx.Begin, two INSERTs) purely to have Redeem reject a request
		// that never carried a token at all.
		if inviteToken == "" {
			return "", ErrUnknownIdentityWithoutInvite
		}
		userID, err = f.createUserFromInvite(claims, inviteToken)
		if err != nil {
			return "", err
		}
	default:
		return "", fmt.Errorf("identity: look up forward-auth identity: %w", err)
	}

	newToken, err := f.sessions.Rotate(previousSessionToken, userID)
	if err != nil {
		return "", fmt.Errorf("identity: start session: %w", err)
	}
	return newToken, nil
}

// updateProfile applies claims to an EXISTING user — name, email and role
// on every entry (see the design doc, "Beide werden bei jedem Eintritt
// aktualisiert" and "Die Rolle wird bei jedem Eintritt neu gelesen").
//
// It reads role_local — NEVER role — as ResolveRoleNames's "what governs
// when the header is absent" input, and writes only role, never
// role_local. That asymmetry IS the fix for the bug team-lead's review
// caught in a first draft of this method: reading role back (the column
// THIS method itself just wrote on a previous entry) would let a role a
// header once asserted quietly survive into a later entry where the
// header goes missing — exactly what "liefert er keine [Rolle] -> die
// lokal zugewiesene Rolle gilt" (design doc) rules out. role_local is
// written ONLY by PromoteUserRole (admin.go); this method never touches
// it, in either direction.
func (f *ForwardAuthLogin) updateProfile(userID string, claims ForwardAuthClaims) error {
	roleLocal, err := f.currentLocalRole(userID)
	if err != nil {
		return err
	}
	roleNames := ResolveRoleNames(claims.RoleHeaderRaw, f.roles, f.defaultRole, roleLocal)

	displayName := claims.Name
	if displayName == "" {
		displayName = claims.Username
	}

	_, err = f.db.Exec(
		`UPDATE users SET
		   display_name = CASE WHEN ? != '' THEN ? ELSE display_name END,
		   email        = CASE WHEN ? != '' THEN ? ELSE email END,
		   role         = ?
		 WHERE id = ?`,
		displayName, displayName, claims.Email, claims.Email, JoinRoleNames(roleNames), userID,
	)
	if err != nil {
		return fmt.Errorf("identity: update forward-auth profile: %w", err)
	}
	return nil
}

// currentLocalRole reads users.role_local — the operator's own assignment,
// written ONLY by PromoteUserRole (admin.go) — the "roleLocal" input
// ResolveRoleNames needs (see role.go's own comment on that parameter for
// why this must never be users.role, the separate effective/display
// column this same type also maintains).
func (f *ForwardAuthLogin) currentLocalRole(userID string) (string, error) {
	var roleLocal string
	if err := f.db.QueryRow(`SELECT role_local FROM users WHERE id = ?`, userID).Scan(&roleLocal); err != nil {
		return "", fmt.Errorf("identity: read local role: %w", err)
	}
	return roleLocal, nil
}

// createUserFromInvite mints a brand-new user for a forward-auth subject
// Tesserarr has never seen, binds the identity, and redeems inviteToken —
// all in the SAME transaction, mirroring OIDCLogin.createUserFromInvite
// for the identical reason (security-audit run-1, see that function's own
// comment in oidc.go).
func (f *ForwardAuthLogin) createUserFromInvite(claims ForwardAuthClaims, inviteToken string) (string, error) {
	userID := newUserID()

	displayName := claims.Name
	if displayName == "" {
		displayName = claims.Username
	}
	if displayName == "" {
		displayName = claims.Subject
	}

	// A brand-new user has no local role assignment yet — "" here is not a
	// stand-in for "not implemented yet", it IS the correct value: nothing
	// has been assigned, so ResolveRoleNames falls through past it to
	// defaultRole exactly like it would for any other user with an empty
	// role_local. role_local ITSELF is left out of the INSERT below on
	// purpose — its schema default ('') is what a brand-new user's local
	// assignment starts as, and nothing here writes to that column: only
	// PromoteUserRole (admin.go) ever does.
	roleNames := ResolveRoleNames(claims.RoleHeaderRaw, f.roles, f.defaultRole, "")

	tx, err := f.db.Begin()
	if err != nil {
		return "", fmt.Errorf("identity: begin user creation: %w", err)
	}
	now := time.Now().UTC().Format(time.RFC3339)
	if _, err := tx.Exec(
		`INSERT INTO users (id, display_name, created_at, email, role) VALUES (?, ?, ?, ?, ?)`,
		userID, displayName, now, nullable(claims.Email), JoinRoleNames(roleNames),
	); err != nil {
		_ = tx.Rollback()
		return "", fmt.Errorf("identity: create user: %w", err)
	}
	if _, err := tx.Exec(
		`INSERT INTO identities (user_id, provider, subject, email) VALUES (?, ?, ?, ?)`,
		userID, forwardAuthProviderName, claims.Subject, nullable(claims.Email),
	); err != nil {
		_ = tx.Rollback()
		return "", fmt.Errorf("identity: bind forward-auth identity: %w", err)
	}
	if err := invite.Redeem(tx, inviteToken, userID); err != nil {
		_ = tx.Rollback()
		return "", fmt.Errorf("%w (redeeming invite: %w)", ErrUnknownIdentityWithoutInvite, err)
	}
	if err := tx.Commit(); err != nil {
		return "", fmt.Errorf("identity: commit user creation: %w", err)
	}
	return userID, nil
}
