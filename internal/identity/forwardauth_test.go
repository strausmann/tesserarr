package identity

import (
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"errors"
	"strings"
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
//
// What this test actually proves: an unknown subject with an empty invite
// token is rejected with ErrUnknownIdentityWithoutInvite — via ONE of TWO
// independent paths, not specifically the early "if inviteToken == \"\""
// guard in Authenticate. Verified directly (temporarily deleting that
// guard during Aufgabe 4's own review): the test stays GREEN even without
// it, because createUserFromInvite's own invite.Redeem(tx, "", userID)
// call rejects the empty token deterministically on its own (no row
// hashes to "", so 0 rows affected, so ErrNotFound, wrapped the same way).
// See the guard's own comment in forwardauth.go for why it stays anyway.
// TestAuthenticateFailsWithBogusInviteTokenAndRedeemFails below isolates
// the SECOND path (a non-empty but invalid token reaching Redeem) on its
// own, for the same reason.
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

// TestAuthenticateNeverRecordsEmailVerificationForForwardAuth is the
// forward-auth counterpart of oidc_test.go's
// TestOIDCLoginRoundTripStoresEmailVerification's "provider omits the
// claim" subtest, proving the OTHER half of EmailVerification's tri-state
// design (email_verification.go): a forward-auth identity's
// identities.email_verified is EmailVerificationUnknown not because some
// source said "no", but because ForwardAuthClaims carries no such field at
// all — a guard's header has nothing equivalent to OIDC's optional
// email_verified claim to send in the first place. This must hold even
// when the guard DOES send an email (proving the column tracks
// CONFIRMATION, not merely the PRESENCE of an email — see
// IdentitySummary.EmailVerification's own comment for why conflating the
// two was the bug a security review caught).
func TestAuthenticateNeverRecordsEmailVerificationForForwardAuth(t *testing.T) {
	login, db := newTestForwardAuthLogin(t, nil, "")
	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}

	if _, err := login.Authenticate(ForwardAuthClaims{
		Subject: "email-verification-fa-subject", Username: "friend", Email: "friend@example.org",
	}, token, ""); err != nil {
		t.Fatalf("Authenticate() returned error: %v", err)
	}

	var raw sql.NullInt64
	if err := db.QueryRow(
		`SELECT email_verified FROM identities WHERE provider = 'forward-auth' AND subject = ?`,
		"email-verification-fa-subject",
	).Scan(&raw); err != nil {
		t.Fatalf("read identities.email_verified: %v", err)
	}
	if got := emailVerificationFromNullInt64(raw); got != EmailVerificationUnknown {
		t.Errorf("identities.email_verified = %v (raw = %+v), want EmailVerificationUnknown — forward-auth has no signal to report, confirmed or not", got, raw)
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

// --- error-path tests ---------------------------------------------------
//
// The tests below close the gap team-lead flagged after merging the rest
// of Aufgabe 4: createUserFromInvite sat at 65.2% statement coverage,
// Authenticate at 75%, updateProfile at 81.8%, currentLocalRole at 75% —
// every uncovered branch was a database-failure path, the exact class of
// bug security-audit run-1 finding 4 was (a compensating DELETE that could
// itself fail). Each test here isolates ONE such branch, mirroring the
// pattern oidc_test.go already uses for OIDCLogin.createUserFromInvite
// (TestOIDCLoginCreateUserFromInviteFailsWhenUserInsertFails,
// ...FailsWhenIdentityInsertFails, ...RollsBackWhenRedeemFails) — dropping
// a table or installing a blocking trigger to force one specific
// statement to fail without touching any other.
//
// Left deliberately uncovered, matching oidc.go's own createUserFromInvite
// (also uncovered at the identical two spots, verified against this same
// coverage profile): db.Begin() failing and tx.Commit() failing. Neither
// has a deterministic, driver-independent way to force it against
// modernc.org/sqlite short of mocking database/sql itself — the existing
// OIDC implementation, which Aufgabe 4 mirrors on purpose, accepts the
// same gap.

// seedForwardAuthUserAndIdentity inserts a user row and binds a
// forward-auth identity to it directly — the fixture-building counterpart
// to oidc_test.go's seedIdentity (which hardcodes oidcProviderName), used
// here so a test can start from "this subject already has an account"
// without going through Authenticate's own create-from-invite path first.
func seedForwardAuthUserAndIdentity(t *testing.T, db *sql.DB, userID, subject string) {
	t.Helper()
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		userID, "seeded test user", "2026-01-01T00:00:00Z",
	); err != nil {
		t.Fatalf("seed user: %v", err)
	}
	if _, err := db.Exec(
		`INSERT INTO identities (user_id, provider, subject, email) VALUES (?, 'forward-auth', ?, ?)`,
		userID, subject, nil,
	); err != nil {
		t.Fatalf("seed forward-auth identity: %v", err)
	}
}

// TestUpdateProfileFailsWhenLocalRoleLookupFails isolates
// currentLocalRole's own error branch AND updateProfile's propagation of
// it (two separate source lines, both previously uncovered) by calling
// updateProfile directly — same package, same pattern oidc_test.go uses to
// call resolveUser directly — with a userID that has no matching users
// row at all, so the role_local SELECT finds nothing to scan.
func TestUpdateProfileFailsWhenLocalRoleLookupFails(t *testing.T) {
	login, _ := newTestForwardAuthLogin(t, nil, "")

	err := login.updateProfile("no-such-user-id", ForwardAuthClaims{Subject: "irrelevant"})
	if err == nil {
		t.Fatal("updateProfile() succeeded for a userID with no users row, want an error")
	}
}

// TestAuthenticateFailsWhenProfileUpdateFails isolates updateProfile's own
// UPDATE-statement failure branch, AND (going through Authenticate rather
// than calling updateProfile directly, unlike the test above) Authenticate's
// "if updateErr != nil" branch for an EXISTING identity — both previously
// uncovered. A trigger blocks only the UPDATE, leaving the preceding
// role_local SELECT (currentLocalRole) untouched, so this isolates
// specifically the write, not the read.
func TestAuthenticateFailsWhenProfileUpdateFails(t *testing.T) {
	login, db := newTestForwardAuthLogin(t, nil, "")
	seedForwardAuthUserAndIdentity(t, db, "user-existing", "existing-subject")

	if _, err := db.Exec(`
		CREATE TRIGGER block_user_update
		BEFORE UPDATE ON users
		BEGIN
			SELECT RAISE(ABORT, 'test: users update blocked');
		END;
	`); err != nil {
		t.Fatalf("install blocking trigger: %v", err)
	}

	_, err := login.Authenticate(ForwardAuthClaims{Subject: "existing-subject"}, "", "")
	if err == nil {
		t.Fatal("Authenticate() succeeded despite a trigger blocking the profile update, want an error")
	}
}

// TestAuthenticateFailsWhenIdentityLookupFails isolates Authenticate's
// "default:" branch — a lookupIdentity failure that is NOT sql.ErrNoRows
// (the ordinary "first time we've seen this subject" case, handled by a
// different branch entirely) — previously uncovered. Dropping the
// identities table makes the underlying SELECT fail with a schema error
// instead of finding zero rows.
func TestAuthenticateFailsWhenIdentityLookupFails(t *testing.T) {
	login, db := newTestForwardAuthLogin(t, nil, "")
	if _, err := db.Exec(`DROP TABLE identities`); err != nil {
		t.Fatalf("drop identities table: %v", err)
	}

	_, err := login.Authenticate(ForwardAuthClaims{Subject: "any-subject"}, "", "")
	if err == nil {
		t.Fatal("Authenticate() succeeded despite a missing identities table, want an error")
	}
	if errors.Is(err, ErrUnknownIdentityWithoutInvite) {
		t.Errorf("Authenticate() reported ErrUnknownIdentityWithoutInvite for a schema failure (err=%v) — "+
			"that sentinel is reserved for an actually rejected invitation", err)
	}
}

// TestAuthenticateFailsWhenSessionRotationFails mirrors
// TestOIDCLoginCallbackFailsWhenSessionRotationFails (oidc_test.go):
// identity resolution against a perfectly healthy database succeeds, but
// the SEPARATE session store's database is closed, so SessionStore.Rotate
// fails — previously uncovered.
func TestAuthenticateFailsWhenSessionRotationFails(t *testing.T) {
	_, db := newTestSessionStore(t, time.Hour)
	seedForwardAuthUserAndIdentity(t, db, "user-existing", "existing-subject")

	brokenSessions, brokenSessionsDB := newTestSessionStore(t, time.Hour)
	if err := brokenSessionsDB.Close(); err != nil {
		t.Fatalf("close broken sessions database: %v", err)
	}

	login := NewForwardAuthLogin(db, brokenSessions, nil, "")

	_, err := login.Authenticate(ForwardAuthClaims{Subject: "existing-subject"}, "", "")
	if err == nil {
		t.Fatal("Authenticate() succeeded despite a broken session store, want an error")
	}
	if !strings.Contains(err.Error(), "start session") {
		t.Errorf("Authenticate() error = %v, want it to mention session creation failing", err)
	}
}

// TestAuthenticateFailsWithBogusInviteTokenAndRedeemFails mirrors
// TestOIDCLoginCreateUserFromInviteRollsBackWhenRedeemFails (oidc_test.go):
// a NON-EMPTY but never-issued token reaches createUserFromInvite (unlike
// TestAuthenticateRejectsUnknownSubjectWithoutInvite's empty-token case,
// which the early guard in Authenticate already intercepts) and
// invite.Redeem rejects it deterministically. Isolates Authenticate's
// "userID, err = f.createUserFromInvite(...); if err != nil" branch AND
// createUserFromInvite's own invite.Redeem-failure branch — both
// previously uncovered — and confirms the transaction actually rolled
// back (no orphaned user left behind by the rejected redemption).
func TestAuthenticateFailsWithBogusInviteTokenAndRedeemFails(t *testing.T) {
	login, db := newTestForwardAuthLogin(t, nil, "")

	_, err := login.Authenticate(ForwardAuthClaims{
		Subject: "orphan-candidate-subject",
	}, "this-invite-token-was-never-created", "")
	if err == nil {
		t.Fatal("Authenticate() succeeded with a bogus invite token, want an error")
	}
	if !errors.Is(err, ErrUnknownIdentityWithoutInvite) {
		t.Errorf("Authenticate() error = %v, want it to wrap ErrUnknownIdentityWithoutInvite", err)
	}

	var count int
	if err := db.QueryRow(`SELECT COUNT(*) FROM users`).Scan(&count); err != nil {
		t.Fatalf("count users: %v", err)
	}
	if count != 0 {
		t.Errorf("users table has %d row(s) after a rejected invite redemption, want 0", count)
	}
}

// TestAuthenticateFailsWhenUserInsertFails mirrors
// TestOIDCLoginCreateUserFromInviteFailsWhenUserInsertFails (oidc_test.go):
// dropping the users table before a valid-invite login isolates
// createUserFromInvite's "INSERT INTO users" failure branch — previously
// uncovered.
func TestAuthenticateFailsWhenUserInsertFails(t *testing.T) {
	login, db := newTestForwardAuthLogin(t, nil, "")
	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}
	if _, err := db.Exec(`DROP TABLE users`); err != nil {
		t.Fatalf("drop users table: %v", err)
	}

	_, err = login.Authenticate(ForwardAuthClaims{Subject: "new-subject"}, token, "")
	if err == nil {
		t.Fatal("Authenticate() succeeded despite a missing users table, want an error")
	}
	if errors.Is(err, ErrUnknownIdentityWithoutInvite) {
		t.Errorf("Authenticate() reported ErrUnknownIdentityWithoutInvite for a schema failure (err=%v) — "+
			"that sentinel is reserved for an actually rejected invitation", err)
	}
}

// TestAuthenticateFailsWhenIdentityInsertFails mirrors
// TestOIDCLoginCreateUserFromInviteFailsWhenIdentityInsertFails
// (oidc_test.go): a trigger blocks every INSERT into identities while
// leaving SELECT untouched, isolating createUserFromInvite's "INSERT INTO
// identities" failure branch — previously uncovered. Dropping the
// identities table outright would not isolate this branch: lookupIdentity
// queries the same table, so a missing table would be caught one branch
// earlier (see TestAuthenticateFailsWhenIdentityLookupFails).
func TestAuthenticateFailsWhenIdentityInsertFails(t *testing.T) {
	login, db := newTestForwardAuthLogin(t, nil, "")
	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}
	if _, err := db.Exec(`
		CREATE TRIGGER block_identity_insert
		BEFORE INSERT ON identities
		BEGIN
			SELECT RAISE(ABORT, 'test: identities insert blocked');
		END;
	`); err != nil {
		t.Fatalf("install blocking trigger: %v", err)
	}

	_, err = login.Authenticate(ForwardAuthClaims{Subject: "new-subject"}, token, "")
	if err == nil {
		t.Fatal("Authenticate() succeeded despite a trigger blocking the identity insert, want an error")
	}
	if errors.Is(err, ErrUnknownIdentityWithoutInvite) {
		t.Errorf("Authenticate() reported ErrUnknownIdentityWithoutInvite for a schema failure (err=%v) — "+
			"that sentinel is reserved for an actually rejected invitation", err)
	}

	var count int
	if err := db.QueryRow(`SELECT COUNT(*) FROM users`).Scan(&count); err != nil {
		t.Fatalf("count users: %v", err)
	}
	if count != 0 {
		t.Errorf("users table has %d row(s) after a rolled-back user creation, want 0", count)
	}
}
