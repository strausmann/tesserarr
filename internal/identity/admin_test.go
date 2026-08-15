package identity

import (
	"database/sql"
	"errors"
	"path/filepath"
	"testing"
	"time"

	"github.com/strausmann/tesserarr/internal/store"
)

func newAdminTestDB(t *testing.T) *sql.DB {
	t.Helper()
	st, err := store.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("store.Open() returned error: %v", err)
	}
	t.Cleanup(func() { _ = st.Close() })
	return st.DB()
}

func insertAdminTestUser(t *testing.T, db *sql.DB, id, email string) {
	t.Helper()
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at, email) VALUES (?, ?, ?, ?)`,
		id, "Test User", time.Now().UTC().Format(time.RFC3339), email,
	); err != nil {
		t.Fatalf("insert test user: %v", err)
	}
}

func insertAdminTestIdentity(t *testing.T, db *sql.DB, userID, provider, subject string) {
	t.Helper()
	if _, err := db.Exec(
		`INSERT INTO identities (user_id, provider, subject) VALUES (?, ?, ?)`,
		userID, provider, subject,
	); err != nil {
		t.Fatalf("insert test identity: %v", err)
	}
}

func TestRemapForwardAuthSubjectRebindsIdentity(t *testing.T) {
	db := newAdminTestDB(t)
	insertAdminTestUser(t, db, "user-1", "")
	insertAdminTestIdentity(t, db, "user-1", forwardAuthProviderName, "old-subject")

	if err := RemapForwardAuthSubject(db, "old-subject", "new-subject"); err != nil {
		t.Fatalf("RemapForwardAuthSubject() returned error: %v", err)
	}

	userID, err := lookupIdentity(db, forwardAuthProviderName, "new-subject")
	if err != nil {
		t.Fatalf("lookupIdentity() after remap returned error: %v", err)
	}
	if userID != "user-1" {
		t.Errorf("userID = %q, want user-1", userID)
	}
	if _, err := lookupIdentity(db, forwardAuthProviderName, "old-subject"); !errors.Is(err, sql.ErrNoRows) {
		t.Errorf("lookupIdentity() for the old subject error = %v, want sql.ErrNoRows", err)
	}
}

func TestRemapForwardAuthSubjectReportsUnknownOldSubject(t *testing.T) {
	db := newAdminTestDB(t)

	if err := RemapForwardAuthSubject(db, "never-existed", "new-subject"); !errors.Is(err, ErrUserNotFound) {
		t.Errorf("error = %v, want ErrUserNotFound", err)
	}
}

func TestPromoteUserRoleSetsLocalRoleByID(t *testing.T) {
	db := newAdminTestDB(t)
	insertAdminTestUser(t, db, "user-1", "friend@example.org")

	if err := PromoteUserRole(db, "user-1", "admin"); err != nil {
		t.Fatalf("PromoteUserRole() returned error: %v", err)
	}

	// PromoteUserRole writes role_local — NEVER role, the separate,
	// always-recomputed effective column both login paths
	// (internal/identity/forwardauth.go's updateProfile and oidc.go's own
	// updateProfile) maintain (see currentLocalRole's own comment for why
	// conflating the two is exactly the bug team-lead's review caught).
	var roleLocal string
	if err := db.QueryRow(`SELECT role_local FROM users WHERE id = ?`, "user-1").Scan(&roleLocal); err != nil {
		t.Fatalf("read role_local: %v", err)
	}
	if roleLocal != "admin" {
		t.Errorf("role_local = %q, want admin", roleLocal)
	}

	// role — the effective column — is UNCHANGED by PromoteUserRole alone;
	// it only catches up on the user's NEXT login, through updateProfile
	// (forward-auth, every entry) or oidc.go's own updateProfile (every
	// RETURNING entry — createUserFromInvite covers brand-new users on both
	// paths).
	// Before Befund B's fix, a user who signs in only via OIDC had no login
	// path that ever recomputed role at all — a genuine gap, not the
	// deliberate asymmetry PromoteUserRole itself has between role and
	// role_local.
	var role string
	if err := db.QueryRow(`SELECT role FROM users WHERE id = ?`, "user-1").Scan(&role); err != nil {
		t.Fatalf("read role: %v", err)
	}
	if role != "" {
		t.Errorf("role = %q, want unchanged (empty — PromoteUserRole must not touch it)", role)
	}
}

func TestPromoteUserRoleReportsUnknownID(t *testing.T) {
	db := newAdminTestDB(t)

	if err := PromoteUserRole(db, "no-such-user-id", "admin"); !errors.Is(err, ErrUserNotFound) {
		t.Errorf("error = %v, want ErrUserNotFound", err)
	}
}

// TestPromoteUserRoleTargetsExactUserEvenWhenEmailIsShared is the
// regression test for Befund A: users.email carries no uniqueness
// constraint (migrations/0001_initial.sql), and internal/api's account
// handler lets any authenticated user set their own email to any value —
// including one someone else has, or one the real owner has not claimed
// yet. Promoting by users.id, not by email, means which of the two rows
// gets promoted is never in question: it is exactly the id the operator
// read off "tesserarr user list" (ListUsers), never whichever row an
// email lookup happened to match first or a would-be attacker's own
// account.
func TestPromoteUserRoleTargetsExactUserEvenWhenEmailIsShared(t *testing.T) {
	db := newAdminTestDB(t)
	insertAdminTestUser(t, db, "user-1", "shared@example.org")
	insertAdminTestUser(t, db, "user-2", "shared@example.org")

	if err := PromoteUserRole(db, "user-2", "admin"); err != nil {
		t.Fatalf("PromoteUserRole() returned error: %v", err)
	}

	var roleLocalUser1, roleLocalUser2 string
	if err := db.QueryRow(`SELECT role_local FROM users WHERE id = ?`, "user-1").Scan(&roleLocalUser1); err != nil {
		t.Fatalf("read user-1 role_local: %v", err)
	}
	if err := db.QueryRow(`SELECT role_local FROM users WHERE id = ?`, "user-2").Scan(&roleLocalUser2); err != nil {
		t.Fatalf("read user-2 role_local: %v", err)
	}
	if roleLocalUser1 != "" {
		t.Errorf("user-1 (NOT the promotion target) role_local = %q, want unchanged (empty)", roleLocalUser1)
	}
	if roleLocalUser2 != "admin" {
		t.Errorf("user-2 (the promotion target) role_local = %q, want admin", roleLocalUser2)
	}
}

// TestRemapForwardAuthSubjectReportsAlreadyBoundSubject covers the plan's
// silence on remapping onto a subject some OTHER account already owns.
// identities has PRIMARY KEY (provider, subject) — SQLite rejects the
// UPDATE outright, so this was never a silent-merge risk; what was
// missing was a caller-recognizable error instead of a raw driver
// message. Both bindings must survive UNCHANGED — a half-applied remap
// would be worse than the raw error it replaces.
func TestRemapForwardAuthSubjectReportsAlreadyBoundSubject(t *testing.T) {
	db := newAdminTestDB(t)
	insertAdminTestUser(t, db, "user-1", "")
	insertAdminTestIdentity(t, db, "user-1", forwardAuthProviderName, "subject-a")
	insertAdminTestUser(t, db, "user-2", "")
	insertAdminTestIdentity(t, db, "user-2", forwardAuthProviderName, "subject-b")

	if err := RemapForwardAuthSubject(db, "subject-a", "subject-b"); !errors.Is(err, ErrSubjectAlreadyBound) {
		t.Errorf("error = %v, want ErrSubjectAlreadyBound", err)
	}

	userID, err := lookupIdentity(db, forwardAuthProviderName, "subject-a")
	if err != nil {
		t.Fatalf("lookupIdentity(subject-a) after rejected remap returned error: %v", err)
	}
	if userID != "user-1" {
		t.Errorf("subject-a still resolves to %q, want user-1 (unchanged)", userID)
	}
	userID, err = lookupIdentity(db, forwardAuthProviderName, "subject-b")
	if err != nil {
		t.Fatalf("lookupIdentity(subject-b) after rejected remap returned error: %v", err)
	}
	if userID != "user-2" {
		t.Errorf("subject-b still resolves to %q, want user-2 (unchanged)", userID)
	}
}

// TestListUsersReturnsEveryUserWithIDAndRoleColumns is "tesserarr user
// list"'s reason to exist: an operator must be able to find the id
// PromoteUserRole now requires, and see role vs. role_local side by side
// rather than guessing which one currently governs access (see role.go's
// UserSummary field comments).
func TestListUsersReturnsEveryUserWithIDAndRoleColumns(t *testing.T) {
	db := newAdminTestDB(t)
	insertAdminTestUser(t, db, "user-2", "second@example.org")
	insertAdminTestUser(t, db, "user-1", "first@example.org")
	if err := PromoteUserRole(db, "user-1", "admin"); err != nil {
		t.Fatalf("PromoteUserRole() returned error: %v", err)
	}
	if _, err := db.Exec(`UPDATE users SET role = 'admin' WHERE id = 'user-1'`); err != nil {
		t.Fatalf("set effective role for fixture: %v", err)
	}

	users, err := ListUsers(db)
	if err != nil {
		t.Fatalf("ListUsers() returned error: %v", err)
	}
	if len(users) != 2 {
		t.Fatalf("ListUsers() returned %d users, want 2", len(users))
	}

	// Ordered by id: "user-1" sorts before "user-2" regardless of insert
	// order — insertAdminTestUser above deliberately inserts user-2 first
	// so this actually exercises the ORDER BY, not incidental insert order.
	if users[0].ID != "user-1" || users[1].ID != "user-2" {
		t.Fatalf("ListUsers() order = [%q, %q], want [user-1, user-2]", users[0].ID, users[1].ID)
	}
	got := users[0]
	if got.Email != "first@example.org" {
		t.Errorf("users[0].Email = %q, want first@example.org", got.Email)
	}
	if got.RoleLocal != "admin" {
		t.Errorf("users[0].RoleLocal = %q, want admin", got.RoleLocal)
	}
	if got.Role != "admin" {
		t.Errorf("users[0].Role = %q, want admin", got.Role)
	}
	if users[1].RoleLocal != "" {
		t.Errorf("users[1].RoleLocal = %q, want empty (never promoted)", users[1].RoleLocal)
	}
}

// TestListUsersReportsNoEmailAsEmptyStringNotNull proves the
// COALESCE(email, ”) in ListUsers' query: a user who has never set an
// email has users.email == NULL, and UserSummary.Email is a plain string
// field with no way to represent NULL — without the COALESCE, Scan would
// fail outright for exactly the users an operator most needs to see
// (brand-new forward-auth accounts with no email header at all).
func TestListUsersReportsNoEmailAsEmptyStringNotNull(t *testing.T) {
	db := newAdminTestDB(t)
	insertAdminTestUser(t, db, "user-1", "")

	users, err := ListUsers(db)
	if err != nil {
		t.Fatalf("ListUsers() returned error: %v", err)
	}
	if len(users) != 1 {
		t.Fatalf("ListUsers() returned %d users, want 1", len(users))
	}
	if users[0].Email != "" {
		t.Errorf("Email = %q, want empty string for a user with no email set", users[0].Email)
	}
}

func TestListUsersFailsWhenQueryFails(t *testing.T) {
	db := newAdminTestDB(t)
	if _, err := db.Exec(`DROP TABLE users`); err != nil {
		t.Fatalf("drop users table: %v", err)
	}

	if _, err := ListUsers(db); err == nil {
		t.Fatal("ListUsers() succeeded despite a missing users table, want an error")
	}
}

// TestListUsersReturnsIdentitiesBoundToEachUser is what
// IdentitySummary/UserSummary.Identities exist for: unlike Email, a
// (provider, subject) pair cannot be set by the account holder — see
// IdentitySummary's own comment — so this is the value an operator
// actually needs surfaced to tell which listed row belongs to which real
// person. Covers a user with two bound identities (the
// "Verwaiste Kennungen zusammenführen" case — the same account reachable
// via both forward-auth and OIDC) and a user with only one, in the SAME
// call, to prove identities are attributed to the correct user and not
// merged across rows.
func TestListUsersReturnsIdentitiesBoundToEachUser(t *testing.T) {
	db := newAdminTestDB(t)
	insertAdminTestUser(t, db, "user-1", "first@example.org")
	insertAdminTestUser(t, db, "user-2", "second@example.org")
	insertAdminTestIdentity(t, db, "user-1", "forward-auth", "sub-fa-1")
	insertAdminTestIdentity(t, db, "user-1", "oidc", "sub-oidc-1")
	insertAdminTestIdentity(t, db, "user-2", "forward-auth", "sub-fa-2")

	users, err := ListUsers(db)
	if err != nil {
		t.Fatalf("ListUsers() returned error: %v", err)
	}
	if len(users) != 2 {
		t.Fatalf("ListUsers() returned %d users, want 2", len(users))
	}

	user1 := users[0]
	if user1.ID != "user-1" {
		t.Fatalf("users[0].ID = %q, want user-1", user1.ID)
	}
	if len(user1.Identities) != 2 {
		t.Fatalf("user-1 Identities = %+v, want 2 entries", user1.Identities)
	}
	want1 := []IdentitySummary{{Provider: "forward-auth", Subject: "sub-fa-1"}, {Provider: "oidc", Subject: "sub-oidc-1"}}
	if user1.Identities[0] != want1[0] || user1.Identities[1] != want1[1] {
		t.Errorf("user-1 Identities = %+v, want %+v", user1.Identities, want1)
	}

	user2 := users[1]
	if user2.ID != "user-2" {
		t.Fatalf("users[1].ID = %q, want user-2", user2.ID)
	}
	if len(user2.Identities) != 1 || user2.Identities[0] != (IdentitySummary{Provider: "forward-auth", Subject: "sub-fa-2"}) {
		t.Errorf("user-2 Identities = %+v, want exactly [{forward-auth sub-fa-2}] — user-1's identities must not bleed into user-2's row", user2.Identities)
	}
}

// TestListUsersReturnsEmptyIdentitiesForAUserWithNone proves a user with
// no row in identities gets an empty (nil) slice, not an error or a
// spurious entry — insertAdminTestUser alone never touches identities, so
// this is the ordinary shape for every fixture the OTHER admin_test.go
// cases build without also calling insertAdminTestIdentity.
func TestListUsersReturnsEmptyIdentitiesForAUserWithNone(t *testing.T) {
	db := newAdminTestDB(t)
	insertAdminTestUser(t, db, "user-1", "solo@example.org")

	users, err := ListUsers(db)
	if err != nil {
		t.Fatalf("ListUsers() returned error: %v", err)
	}
	if len(users) != 1 {
		t.Fatalf("ListUsers() returned %d users, want 1", len(users))
	}
	if len(users[0].Identities) != 0 {
		t.Errorf("Identities = %+v, want none", users[0].Identities)
	}
}
