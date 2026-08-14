package identity

import (
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"errors"
	"testing"
	"time"

	"github.com/strausmann/tesserarr/internal/config"
	"github.com/strausmann/tesserarr/internal/invite"
)

func newTestForwardAuthLogin(t *testing.T, roles config.RoleMap, defaultRole string) (*ForwardAuthLogin, *sql.DB) {
	t.Helper()
	sessions, db := newTestSessionStore(t, time.Hour)
	return NewForwardAuthLogin(db, sessions, roles, defaultRole), db
}

func readUserRole(t *testing.T, db *sql.DB, userID string) string {
	t.Helper()
	var role string
	if err := db.QueryRow(`SELECT role FROM users WHERE id = ?`, userID).Scan(&role); err != nil {
		t.Fatalf("read role: %v", err)
	}
	return role
}

func userIDForForwardAuthSubject(t *testing.T, db *sql.DB, subject string) string {
	t.Helper()
	var userID string
	if err := db.QueryRow(
		`SELECT user_id FROM identities WHERE provider = 'forward-auth' AND subject = ?`, subject,
	).Scan(&userID); err != nil {
		t.Fatalf("read user id for subject %q: %v", subject, err)
	}
	return userID
}

// Verifikationspunkt 3: unbekannter Nutzer ohne Einladung bekommt kein Konto.
func TestAuthenticateRejectsUnknownSubjectWithoutInvite(t *testing.T) {
	login, _ := newTestForwardAuthLogin(t, nil, "")

	_, err := login.Authenticate(ForwardAuthClaims{Subject: "unknown-subject"}, "", "")
	if !errors.Is(err, ErrUnknownIdentityWithoutInvite) {
		t.Errorf("Authenticate() error = %v, want ErrUnknownIdentityWithoutInvite", err)
	}
}

// Verifikationspunkt 4: unbekannter Nutzer MIT gültiger Einladung bekommt ein
// Konto, und die Einladung ist danach nachweislich eingelöst.
func TestAuthenticateCreatesUserFromValidInviteAndRedeemsIt(t *testing.T) {
	login, db := newTestForwardAuthLogin(t, nil, "")
	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}

	sessionToken, err := login.Authenticate(ForwardAuthClaims{
		Subject: "new-subject", Username: "friend", Email: "friend@example.org",
	}, token, "")
	if err != nil {
		t.Fatalf("Authenticate() returned error: %v", err)
	}
	if sessionToken == "" {
		t.Fatal("Authenticate() returned an empty session token")
	}

	userID := userIDForForwardAuthSubject(t, db, "new-subject")

	var redeemedBy sql.NullString
	if err := db.QueryRow(
		`SELECT redeemed_by FROM invitations WHERE token_hash = ?`, hashInviteTokenForTest(token),
	).Scan(&redeemedBy); err != nil {
		t.Fatalf("read invitation: %v", err)
	}
	if !redeemedBy.Valid || redeemedBy.String != userID {
		t.Errorf("invitation redeemed_by = %+v, want %q", redeemedBy, userID)
	}
}

func TestAuthenticateRejectsEmptySubject(t *testing.T) {
	login, _ := newTestForwardAuthLogin(t, nil, "")

	_, err := login.Authenticate(ForwardAuthClaims{Subject: ""}, "", "")
	if !errors.Is(err, ErrForwardAuthSubjectEmpty) {
		t.Errorf("Authenticate() error = %v, want ErrForwardAuthSubjectEmpty", err)
	}
}

// Verifikationspunkt 7 (DB-Ebene): ein unbekannter Rollenwert in der
// Kopfzeile ergibt bei der NEUANLAGE die Standard-Rolle.
func TestAuthenticateNewUserWithUnknownRoleHeaderGetsDefaultRole(t *testing.T) {
	roles, err := config.ParseRoleMap("familie:request")
	if err != nil {
		t.Fatalf("config.ParseRoleMap() returned error: %v", err)
	}
	login, db := newTestForwardAuthLogin(t, roles, "familie")
	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}

	if _, err := login.Authenticate(ForwardAuthClaims{
		Subject: "new-subject", RoleHeaderRaw: "typo-role",
	}, token, ""); err != nil {
		t.Fatalf("Authenticate() returned error: %v", err)
	}

	userID := userIDForForwardAuthSubject(t, db, "new-subject")
	if got := readUserRole(t, db, userID); got != "familie" {
		t.Errorf("role = %q, want familie (the default)", got)
	}
}

// Verifikationspunkt 8: eine Herabstufung im Portal wirkt beim NÄCHSTEN
// Eintritt, nicht erst nach einer Neuanlage.
func TestAuthenticateAppliesRoleDowngradeOnNextEntry(t *testing.T) {
	roles, err := config.ParseRoleMap("admin:administer+request,gast:request")
	if err != nil {
		t.Fatalf("config.ParseRoleMap() returned error: %v", err)
	}
	login, db := newTestForwardAuthLogin(t, roles, "gast")
	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}

	if _, err := login.Authenticate(ForwardAuthClaims{
		Subject: "returning-subject", RoleHeaderRaw: "admin",
	}, token, ""); err != nil {
		t.Fatalf("first Authenticate() returned error: %v", err)
	}
	userID := userIDForForwardAuthSubject(t, db, "returning-subject")
	if got := readUserRole(t, db, userID); got != "admin" {
		t.Fatalf("role after first entry = %q, want admin", got)
	}

	if _, err := login.Authenticate(ForwardAuthClaims{
		Subject: "returning-subject", RoleHeaderRaw: "gast",
	}, "", ""); err != nil {
		t.Fatalf("second Authenticate() returned error: %v", err)
	}
	if got := readUserRole(t, db, userID); got != "gast" {
		t.Errorf("role after second entry = %q, want gast (the downgrade took effect)", got)
	}
}

// setUserLocalRoleForTest writes users.role_local directly — simulating
// what ONLY PromoteUserRole (admin.go, Aufgabe 10) is allowed to do in the
// real system. Used here purely to set up a fixture; ForwardAuthLogin
// itself never calls anything that would let a test reach the same state
// through Authenticate.
func setUserLocalRoleForTest(t *testing.T, db *sql.DB, userID, roleLocal string) {
	t.Helper()
	if _, err := db.Exec(`UPDATE users SET role_local = ? WHERE id = ?`, roleLocal, userID); err != nil {
		t.Fatalf("set role_local: %v", err)
	}
}

// Verifikationspunkt 10 (DB-Ebene, the case team-lead's review specifically
// asked for): a role header governs one entry, but its result must NOT
// leak into a LATER entry that carries no header — that entry falls to
// the OPERATOR's role_local (unaffected by the earlier header), or to the
// default if no operator has ever assigned one. This is the reverse of
// the bug a first draft of this plan had: reading users.role (the
// column the FIRST entry itself wrote) back in as if it were role_local
// would have kept "admin" alive with no header ever asserting it again.
func TestAuthenticateDropsToLocalRoleNotToThePreviousHeaderRoleWhenHeaderIsAbsentOnReturn(t *testing.T) {
	roles, err := config.ParseRoleMap("admin:administer+request,gast:request")
	if err != nil {
		t.Fatalf("config.ParseRoleMap() returned error: %v", err)
	}
	login, db := newTestForwardAuthLogin(t, roles, "gast")
	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}

	if _, err := login.Authenticate(ForwardAuthClaims{
		Subject: "returning-subject", RoleHeaderRaw: "admin",
	}, token, ""); err != nil {
		t.Fatalf("first Authenticate() (with header) returned error: %v", err)
	}
	userID := userIDForForwardAuthSubject(t, db, "returning-subject")
	if got := readUserRole(t, db, userID); got != "admin" {
		t.Fatalf("role after first entry = %q, want admin", got)
	}

	if _, err := login.Authenticate(ForwardAuthClaims{
		Subject: "returning-subject", // no RoleHeaderRaw at all this time
	}, "", ""); err != nil {
		t.Fatalf("second Authenticate() (no header) returned error: %v", err)
	}
	if got := readUserRole(t, db, userID); got != "gast" {
		t.Errorf("role after header-less entry = %q, want gast (the DEFAULT — role_local was never set, so \"admin\" from the first entry must not survive)", got)
	}
}

// The other half of the same case: if an OPERATOR (not a header) has set
// role_local, a header-less entry falls to THAT — again regardless of
// whatever a header set on some earlier entry.
func TestAuthenticateFallsBackToOperatorAssignedLocalRoleNotToThePreviousHeaderRole(t *testing.T) {
	roles, err := config.ParseRoleMap("admin:administer+request,familie:request,gast:request")
	if err != nil {
		t.Fatalf("config.ParseRoleMap() returned error: %v", err)
	}
	login, db := newTestForwardAuthLogin(t, roles, "gast")
	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}

	if _, err := login.Authenticate(ForwardAuthClaims{
		Subject: "returning-subject", RoleHeaderRaw: "admin",
	}, token, ""); err != nil {
		t.Fatalf("first Authenticate() (with header) returned error: %v", err)
	}
	userID := userIDForForwardAuthSubject(t, db, "returning-subject")

	setUserLocalRoleForTest(t, db, userID, "familie") // what an operator actually assigned, via user promote

	if _, err := login.Authenticate(ForwardAuthClaims{
		Subject: "returning-subject", // no RoleHeaderRaw at all this time
	}, "", ""); err != nil {
		t.Fatalf("second Authenticate() (no header) returned error: %v", err)
	}
	if got := readUserRole(t, db, userID); got != "familie" {
		t.Errorf("role after header-less entry = %q, want familie (role_local), not admin (the earlier header) and not gast (the default)", got)
	}
}

// Name und E-Mail werden bei jedem Eintritt aktualisiert, aber eine leere
// eingehende E-Mail löscht eine bereits gespeicherte nicht.
func TestAuthenticateUpdatesNameAndEmailButKeepsEmailWhenHeaderIsEmpty(t *testing.T) {
	login, db := newTestForwardAuthLogin(t, nil, "")
	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}

	if _, err := login.Authenticate(ForwardAuthClaims{
		Subject: "returning-subject", Name: "First Name", Email: "first@example.org",
	}, token, ""); err != nil {
		t.Fatalf("first Authenticate() returned error: %v", err)
	}
	userID := userIDForForwardAuthSubject(t, db, "returning-subject")

	if _, err := login.Authenticate(ForwardAuthClaims{
		Subject: "returning-subject", Name: "Second Name", // no Email this time
	}, "", ""); err != nil {
		t.Fatalf("second Authenticate() returned error: %v", err)
	}

	var displayName string
	var email sql.NullString
	if err := db.QueryRow(`SELECT display_name, email FROM users WHERE id = ?`, userID).Scan(&displayName, &email); err != nil {
		t.Fatalf("read user: %v", err)
	}
	if displayName != "Second Name" {
		t.Errorf("display_name = %q, want Second Name (updated)", displayName)
	}
	if !email.Valid || email.String != "first@example.org" {
		t.Errorf("email = %+v, want first@example.org (kept, not cleared)", email)
	}
}

// hashInviteTokenForTest mirrors invite.go's own unexported hashToken —
// duplicated here (same value, computed the same way, sha256 hex) only
// because invite.hashToken is unexported and this test lives in a
// different package. Kept to exactly one line so a change to invite's
// hashing scheme is easy to notice and mirror.
func hashInviteTokenForTest(token string) string {
	sum := sha256.Sum256([]byte(token))
	return hex.EncodeToString(sum[:])
}
