package identity

import (
	"database/sql"
	"errors"
	"fmt"
)

// ErrUserNotFound is returned by RemapForwardAuthSubject and
// PromoteUserRole when nothing matches — a bare "0 rows affected" is not
// self-explanatory enough for a command-line operator to act on.
var ErrUserNotFound = errors.New("identity: no matching user")

// ErrAmbiguousEmail is returned by PromoteUserRole when more than one user
// shares the given email — users.email carries no uniqueness constraint,
// so this is possible, if unusual.
var ErrAmbiguousEmail = errors.New("identity: more than one user has this email")

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
// user whose email is email. This is the command line's way to hand out a
// role without a guard's role header, and the only way to create the very
// first administrator before any guard is configured at all (see the
// design doc, "Wenn der Wächter keine Rolle liefert"). The user's
// EFFECTIVE role (users.role, what the account view shows) only catches
// up on their next login — this function does not, and must not, touch it
// directly.
func PromoteUserRole(db *sql.DB, email, role string) error {
	rows, err := db.Query(`SELECT id FROM users WHERE email = ?`, email)
	if err != nil {
		return fmt.Errorf("identity: promote user: find by email: %w", err)
	}
	defer func() { _ = rows.Close() }()

	var id string
	count := 0
	for rows.Next() {
		if err := rows.Scan(&id); err != nil {
			return fmt.Errorf("identity: promote user: scan: %w", err)
		}
		count++
	}
	if err := rows.Err(); err != nil {
		return fmt.Errorf("identity: promote user: iterate: %w", err)
	}
	if count == 0 {
		return ErrUserNotFound
	}
	if count > 1 {
		return ErrAmbiguousEmail
	}

	if _, err := db.Exec(`UPDATE users SET role_local = ? WHERE id = ?`, role, id); err != nil {
		return fmt.Errorf("identity: promote user: %w", err)
	}
	return nil
}
