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

func TestPromoteUserRoleSetsLocalRoleByEmail(t *testing.T) {
	db := newAdminTestDB(t)
	insertAdminTestUser(t, db, "user-1", "friend@example.org")

	if err := PromoteUserRole(db, "friend@example.org", "admin"); err != nil {
		t.Fatalf("PromoteUserRole() returned error: %v", err)
	}

	// PromoteUserRole writes role_local — NEVER role, the separate,
	// always-recomputed effective column internal/identity/forwardauth.go
	// maintains (see that file's own comment on currentLocalRole for why
	// conflating the two is exactly the bug team-lead's review caught).
	var roleLocal string
	if err := db.QueryRow(`SELECT role_local FROM users WHERE id = ?`, "user-1").Scan(&roleLocal); err != nil {
		t.Fatalf("read role_local: %v", err)
	}
	if roleLocal != "admin" {
		t.Errorf("role_local = %q, want admin", roleLocal)
	}

	// role — the effective column — is UNCHANGED by PromoteUserRole alone;
	// it only catches up on the user's NEXT login (OIDC or forward-auth).
	// This is deliberate, not a gap: see Aufgabe 4's updateProfile/
	// createUserFromInvite, the only two places that ever write role.
	var role string
	if err := db.QueryRow(`SELECT role FROM users WHERE id = ?`, "user-1").Scan(&role); err != nil {
		t.Fatalf("read role: %v", err)
	}
	if role != "" {
		t.Errorf("role = %q, want unchanged (empty — PromoteUserRole must not touch it)", role)
	}
}

func TestPromoteUserRoleReportsUnknownEmail(t *testing.T) {
	db := newAdminTestDB(t)

	if err := PromoteUserRole(db, "nobody@example.org", "admin"); !errors.Is(err, ErrUserNotFound) {
		t.Errorf("error = %v, want ErrUserNotFound", err)
	}
}

func TestPromoteUserRoleReportsAmbiguousEmail(t *testing.T) {
	db := newAdminTestDB(t)
	insertAdminTestUser(t, db, "user-1", "shared@example.org")
	insertAdminTestUser(t, db, "user-2", "shared@example.org")

	if err := PromoteUserRole(db, "shared@example.org", "admin"); !errors.Is(err, ErrAmbiguousEmail) {
		t.Errorf("error = %v, want ErrAmbiguousEmail", err)
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
