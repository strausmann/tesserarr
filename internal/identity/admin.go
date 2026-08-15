package identity

import (
	"database/sql"
	"errors"
	"fmt"

	"modernc.org/sqlite"
	sqlite3 "modernc.org/sqlite/lib"
)

// ErrUserNotFound is returned by RemapForwardAuthSubject and
// PromoteUserRole when nothing matches — a bare "0 rows affected" is not
// self-explanatory enough for a command-line operator to act on.
var ErrUserNotFound = errors.New("identity: no matching user")

// ErrSubjectAlreadyBound is returned by RemapForwardAuthSubject when
// newSubject already belongs to a DIFFERENT account. The design doc's
// "Verwaiste Kennungen zusammenführen" is silent on this case; the plan
// that introduced this file was too. identities' own
// PRIMARY KEY (provider, subject) already rejects the UPDATE outright —
// there was never a silent-merge risk — but without this, the operator
// only ever saw a raw driver message ("constraint failed: UNIQUE
// constraint failed: identities.provider, identities.subject (1555)")
// instead of something they can act on.
var ErrSubjectAlreadyBound = errors.New("identity: subject is already bound to a different account")

// RemapForwardAuthSubject rebinds a forward-auth identity from oldSubject
// to newSubject — the operator's fix for "Verwaiste Kennungen
// zusammenführen" in the design doc: a guard without a stable identity
// header (Authelia, oauth2-proxy) issues a NEW subject value when a user
// renames themselves, and ADR-0004's invitation gate then correctly
// refuses the "new" subject rather than silently merging it with the old
// account.
func RemapForwardAuthSubject(db *sql.DB, oldSubject, newSubject string) error {
	result, err := db.Exec(
		`UPDATE identities SET subject = ? WHERE provider = ? AND subject = ?`,
		newSubject, forwardAuthProviderName, oldSubject,
	)
	if err != nil {
		// A typed check, not a string match on err.Error(): identities'
		// PRIMARY KEY (provider, subject) is what turns "newSubject
		// already belongs to someone else" into a driver-level
		// constraint violation, not application logic — see
		// ErrSubjectAlreadyBound's doc comment. Confirmed empirically
		// (not assumed) that modernc.org/sqlite wraps this as *sqlite.Error
		// with Code() == SQLITE_CONSTRAINT_PRIMARYKEY (1555), reachable
		// through this function's own error wrapping via errors.As.
		var sqliteErr *sqlite.Error
		if errors.As(err, &sqliteErr) && sqliteErr.Code() == sqlite3.SQLITE_CONSTRAINT_PRIMARYKEY {
			return ErrSubjectAlreadyBound
		}
		return fmt.Errorf("identity: remap forward-auth subject: %w", err)
	}
	affected, err := result.RowsAffected()
	if err != nil {
		return fmt.Errorf("identity: remap forward-auth subject: rows affected: %w", err)
	}
	if affected == 0 {
		return ErrUserNotFound
	}
	return nil
}

// PromoteUserRole sets role_local — NEVER role, see
// internal/identity/forwardauth.go's currentLocalRole and role.go's
// ResolveRoleNames for why the two must stay separate columns — on the
// user identified by userID. This is the command line's way to hand out a
// role without a guard's role header, and the only way to create the very
// first administrator before any guard is configured at all (see the
// design doc, "Wenn der Wächter keine Rolle liefert"). The user's
// EFFECTIVE role (users.role, what the account view shows) only catches
// up on their next login — this function does not, and must not, touch it
// directly.
//
// userID is deliberately users.id — the value ListUsers ("tesserarr user
// list") prints — and NOT an email, which an earlier version of this
// function accepted. users.email carries no uniqueness constraint (see
// migrations/0001_initial.sql), and internal/api's account handler lets
// ANY authenticated user set their own email to ANY value, including one
// nobody has claimed yet. Looking a promotion target up by email meant an
// attacker who set their own email to a target's email BEFORE the real
// owner ever did would have matched exactly one row — themselves — and
// PromoteUserRole would have promoted them instead, silently, with no
// error and no ambiguity to catch. users.id has none of that: it is minted
// once, by the system itself, at account creation (newUserID) and no
// account holder — nor any HTTP handler — can ever change it afterwards.
func PromoteUserRole(db *sql.DB, userID, role string) error {
	result, err := db.Exec(`UPDATE users SET role_local = ? WHERE id = ?`, role, userID)
	if err != nil {
		return fmt.Errorf("identity: promote user: %w", err)
	}
	affected, err := result.RowsAffected()
	if err != nil {
		return fmt.Errorf("identity: promote user: rows affected: %w", err)
	}
	if affected == 0 {
		return ErrUserNotFound
	}
	return nil
}

// IdentitySummary is one identity bound to a user — a (provider, subject)
// pair from the identities table (migrations/0001_initial.sql). Unlike
// UserSummary.Email, NEITHER field here is ever settable by the account
// holder through any HTTP endpoint: Authenticate (forwardauth.go) and
// resolveUser (oidc.go) are the only two places identities is ever
// written to, and both take Subject exclusively from a source the caller
// does not control end to end — the trusted guard's header (checked
// against TESSERARR_TRUSTED_PROXIES and the shared secret before
// forwardauth.go ever sees it) or the OIDC provider's own ID token. That
// is what makes a (provider, subject) pair the answer to "who actually
// owns this login" — see PromoteUserRole's own comment for why
// UserSummary.Email is emphatically NOT that answer.
type IdentitySummary struct {
	// Provider is identities.provider — forwardAuthProviderName
	// ("forward-auth") or oidcProviderName (oidc.go), never a value the
	// account holder supplies.
	Provider string
	// Subject is identities.subject — see this type's own comment.
	Subject string
}

// UserSummary is one row of what ListUsers reports — enough for an
// operator to find the id PromoteUserRole now requires, without ever
// showing role_local's raw value as if it were what governs access right
// now (see role.go's ResolveRoleNames for why role, not role_local, is
// the answer to "what can this person currently do").
type UserSummary struct {
	// ID is users.id — what PromoteUserRole and RemapForwardAuthSubject's
	// target both key on.
	ID string
	// DisplayName is users.display_name — never empty (NOT NULL).
	DisplayName string
	// Email is users.email, or "" if the user has never set one.
	//
	// UNVERIFIED — this is emphatically NOT proof of who owns this
	// account. internal/api/account.go's handleUpdateAccount lets ANY
	// signed-in user set it to ANY string of at most 254 bytes, including
	// one another account already carries (users.email has no uniqueness
	// constraint — migrations/0001_initial.sql); a forward-auth guard
	// entry can also overwrite it on every login (see
	// ForwardAuthLogin.updateProfile, forwardauth.go) with whatever its
	// own email header happens to carry. An operator who reads THIS field
	// as "this is who they are" and acts on it (e.g. to decide who to
	// promote) can be handing a role to the wrong account entirely — see
	// Identities below for the field that actually answers that question,
	// and TestPromoteUserRoleTargetsExactUserEvenWhenEmailIsShared for why
	// PromoteUserRole itself never accepts this value as a lookup key.
	Email string
	// Role is the CURRENTLY EFFECTIVE role (users.role) — recomputed at
	// every login by ResolveRoleNames, comma-joined if more than one name
	// applies.
	Role string
	// RoleLocal is the operator's OWN assignment (users.role_local, written
	// only by PromoteUserRole) — "" if no operator has ever promoted this
	// user. This is the INPUT ResolveRoleNames falls back to when a
	// header-based login carries no role, not necessarily what is
	// currently effective; see Role for that.
	RoleLocal string
	// Identities are the (provider, subject) pairs actually bound to this
	// user — see IdentitySummary's own comment for why these, and not
	// Email, are what an operator can trust to identify who this account
	// belongs to. Every user has at least one by construction
	// (createUserFromInvite in forwardauth.go/oidc.go always inserts one
	// in the same transaction as the user row), but this is populated by
	// a second, independent query (see ListUsers below) rather than
	// assumed non-empty.
	Identities []IdentitySummary
}

// ListUsers returns every user, ordered by id, so an operator running
// "tesserarr user list" can find the id to hand to "tesserarr user
// promote" — see PromoteUserRole's own comment for why promoting by email
// is no longer supported.
func ListUsers(db *sql.DB) ([]UserSummary, error) {
	rows, err := db.Query(`SELECT id, display_name, COALESCE(email, ''), role, role_local FROM users ORDER BY id`)
	if err != nil {
		return nil, fmt.Errorf("identity: list users: %w", err)
	}
	defer func() { _ = rows.Close() }()

	var out []UserSummary
	index := make(map[string]int)
	for rows.Next() {
		var u UserSummary
		if err := rows.Scan(&u.ID, &u.DisplayName, &u.Email, &u.Role, &u.RoleLocal); err != nil {
			return nil, fmt.Errorf("identity: list users: scan: %w", err)
		}
		index[u.ID] = len(out)
		out = append(out, u)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("identity: list users: iterate: %w", err)
	}

	// A second, separate query rather than a JOIN: a user can have more
	// than one identity bound (a forward-auth entry and an OIDC entry both
	// resolving to the same account is exactly what RemapForwardAuthSubject
	// and the "Verwaiste Kennungen zusammenführen" design doc section
	// exist for), and a JOIN would either duplicate every other column
	// per identity row or need a database-specific aggregate — a second
	// query keyed by user_id and merged in Go here needs neither.
	idRows, err := db.Query(`SELECT user_id, provider, subject FROM identities ORDER BY user_id, provider, subject`)
	if err != nil {
		return nil, fmt.Errorf("identity: list users: identities: %w", err)
	}
	defer func() { _ = idRows.Close() }()

	for idRows.Next() {
		var userID string
		var id IdentitySummary
		if err := idRows.Scan(&userID, &id.Provider, &id.Subject); err != nil {
			return nil, fmt.Errorf("identity: list users: identities: scan: %w", err)
		}
		// A row here for a user_id not in index would mean identities
		// carries an orphaned reference — impossible under its own
		// REFERENCES users(id) ON DELETE CASCADE (migrations/0001_initial.sql)
		// — but this is a read path with no transaction of its own
		// spanning both queries, so a concurrent user deletion between
		// them is at least conceivable; silently skipping such a row is
		// the same "outcome no different from asking a heartbeat sooner"
		// shape ListUsers already accepts elsewhere, not a new risk.
		if i, ok := index[userID]; ok {
			out[i].Identities = append(out[i].Identities, id)
		}
	}
	if err := idRows.Err(); err != nil {
		return nil, fmt.Errorf("identity: list users: identities: iterate: %w", err)
	}

	return out, nil
}
