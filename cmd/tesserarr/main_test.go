package main

import (
	"bytes"
	"database/sql"
	"errors"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/strausmann/tesserarr/internal/api"
	"github.com/strausmann/tesserarr/internal/invite"
	"github.com/strausmann/tesserarr/internal/store"
)

// newTestDB opens a fresh database with the real embedded migrations
// applied, mirroring internal/invite's own test helper — createInvite must
// work against the actual schema, not a stand-in.
func newTestDB(t *testing.T) *sql.DB {
	t.Helper()
	st, err := store.Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("store.Open() returned error: %v", err)
	}
	t.Cleanup(func() { _ = st.Close() })
	return st.DB()
}

// tokenFromOutput extracts the plaintext token from createInvite's stdout,
// e.g. "token:       abcd\n..." -> "abcd".
func tokenFromOutput(t *testing.T, out string) string {
	t.Helper()
	for _, line := range strings.Split(out, "\n") {
		if rest, ok := strings.CutPrefix(line, "token:"); ok {
			return strings.TrimSpace(rest)
		}
	}
	t.Fatalf("output has no \"token:\" line:\n%s", out)
	return ""
}

func TestCreateInvitePrintsTokenAndRedeemLink(t *testing.T) {
	db := newTestDB(t)
	var out bytes.Buffer

	if err := createInvite(db, "https://tesserarr.example.org", 0, false, &out); err != nil {
		t.Fatalf("createInvite() returned error: %v", err)
	}

	token := tokenFromOutput(t, out.String())
	if token == "" {
		t.Fatal("createInvite() printed an empty token")
	}

	wantLink := "redeem link: https://tesserarr.example.org/invite/" + token
	if !strings.Contains(out.String(), wantLink) {
		t.Errorf("output = %q, want a line containing %q", out.String(), wantLink)
	}
}

func TestCreateInviteTrimsTrailingSlashFromBaseURL(t *testing.T) {
	db := newTestDB(t)
	var out bytes.Buffer

	if err := createInvite(db, "https://tesserarr.example.org/", 0, false, &out); err != nil {
		t.Fatalf("createInvite() returned error: %v", err)
	}

	if strings.Contains(out.String(), "//invite/") {
		t.Errorf("output = %q, contains a doubled slash before /invite/", out.String())
	}
}

func TestCreateInviteMintedTokenIsRedeemable(t *testing.T) {
	db := newTestDB(t)
	var out bytes.Buffer

	if err := createInvite(db, "https://tesserarr.example.org", 0, false, &out); err != nil {
		t.Fatalf("createInvite() returned error: %v", err)
	}
	token := tokenFromOutput(t, out.String())

	// Redeem's redeemed_by column is a foreign key into users; a real caller
	// creates this row as part of resolving the invitee's identity. The
	// point under test is only that the token createInvite printed is the
	// one Redeem accepts — not identity resolution, which belongs to
	// internal/identity.
	const userID = "test-user"
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		userID, "Test User", time.Now().UTC().Format(time.RFC3339),
	); err != nil {
		t.Fatalf("insert test user: %v", err)
	}

	if err := invite.Redeem(db, token, userID); err != nil {
		t.Errorf("invite.Redeem() with the token createInvite printed returned error: %v", err)
	}
}

func TestCreateInviteForwardsTTL(t *testing.T) {
	db := newTestDB(t)
	var out bytes.Buffer

	ttl := 2 * time.Hour
	// Truncated to whole seconds because the stored value round-trips
	// through RFC3339 text (see invite.timeFormat), which drops the
	// sub-second component createInvite's own before/after would otherwise
	// still carry.
	before := time.Now().UTC().Truncate(time.Second)
	if err := createInvite(db, "https://tesserarr.example.org", ttl, false, &out); err != nil {
		t.Fatalf("createInvite() returned error: %v", err)
	}
	after := time.Now().UTC().Truncate(time.Second).Add(time.Second)

	var validUntil string
	if err := db.QueryRow(`SELECT valid_until FROM invitations`).Scan(&validUntil); err != nil {
		t.Fatalf("read stored invitation: %v", err)
	}
	got, err := time.Parse(time.RFC3339, validUntil)
	if err != nil {
		t.Fatalf("parse stored valid_until %q: %v", validUntil, err)
	}

	// The custom ttl (2h), not invite.DefaultTTL (7 days), must have reached
	// invite.Create — bracket the stored expiry against [before, after]+ttl
	// rather than asserting an exact timestamp, since Create stamps "now"
	// itself.
	if got.Before(before.Add(ttl)) || got.After(after.Add(ttl)) {
		t.Errorf("stored valid_until = %v, want within [%v, %v] (ttl=%v not forwarded to invite.Create?)",
			got, before.Add(ttl), after.Add(ttl), ttl)
	}
}

func TestRunInviteRejectsMissingSubcommand(t *testing.T) {
	if err := runInvite(nil); err == nil {
		t.Fatal("runInvite(nil) returned nil error, want a usage error")
	}
}

func TestRunInviteRejectsUnknownSubcommand(t *testing.T) {
	err := runInvite([]string{"bogus"})
	if err == nil {
		t.Fatal("runInvite([\"bogus\"]) returned nil error, want an error naming the unknown subcommand")
	}
	if !strings.Contains(err.Error(), "bogus") {
		t.Errorf("error = %q, want it to mention the unknown subcommand %q", err.Error(), "bogus")
	}
}

func TestCreateInvitePrintsForwardAuthLinkWhenEnabled(t *testing.T) {
	db := newTestDB(t)
	var out bytes.Buffer

	if err := createInvite(db, "https://tesserarr.example.org", 0, true, &out); err != nil {
		t.Fatalf("createInvite() returned error: %v", err)
	}

	token := tokenFromOutput(t, out.String())
	wantLink := "redeem link (forward-auth): https://tesserarr.example.org" + api.LoginForwardAuthPath + "?invite=" + token
	if !strings.Contains(out.String(), wantLink) {
		t.Errorf("output = %q, want a line containing %q", out.String(), wantLink)
	}
}

func TestCreateInviteOmitsForwardAuthLinkWhenDisabled(t *testing.T) {
	db := newTestDB(t)
	var out bytes.Buffer

	if err := createInvite(db, "https://tesserarr.example.org", 0, false, &out); err != nil {
		t.Fatalf("createInvite() returned error: %v", err)
	}

	if strings.Contains(out.String(), "forward-auth") {
		t.Errorf("output = %q, must not mention forward-auth when it is disabled", out.String())
	}
}

func TestRunUserRejectsMissingSubcommand(t *testing.T) {
	if err := runUser(nil); err == nil {
		t.Fatal("runUser(nil) returned nil error, want a usage error")
	}
}

func TestRunUserRejectsUnknownSubcommand(t *testing.T) {
	err := runUser([]string{"bogus"})
	if err == nil {
		t.Fatal("runUser([\"bogus\"]) returned nil error, want an error naming the unknown subcommand")
	}
}

// errUsage, not just "err != nil": config.Load also fails in this test
// process (no TESSERARR_BASE_URL set), so a deleted argument-count guard
// still returns SOME error — errors.Is is what actually tells the guard
// apart from that unrelated later failure. See errUsage's doc comment.
func TestRunUserRemapRejectsWrongArgumentCount(t *testing.T) {
	err := runUser([]string{"remap", "only-one-arg"})
	if !errors.Is(err, errUsage) {
		t.Fatalf("runUser([\"remap\", \"only-one-arg\"]) error = %v, want errors.Is(err, errUsage)", err)
	}
}

func TestRunUserPromoteRejectsWrongArgumentCount(t *testing.T) {
	err := runUser([]string{"promote", "only-one-arg"})
	if !errors.Is(err, errUsage) {
		t.Fatalf("runUser([\"promote\", \"only-one-arg\"]) error = %v, want errors.Is(err, errUsage)", err)
	}
}

func TestRunUserListRejectsArguments(t *testing.T) {
	err := runUser([]string{"list", "unexpected-arg"})
	if !errors.Is(err, errUsage) {
		t.Fatalf(`runUser(["list", "unexpected-arg"]) error = %v, want errors.Is(err, errUsage)`, err)
	}
}

// TestListUsersPrintsEveryUserWithPlaceholdersForEmptyFields is
// listUsers' own test, split out from runUserList the same way
// createInvite is split out from runInviteCreate (see that function's own
// comment) so it can run against a temporary, migrated database without
// going through config.Load.
func TestListUsersPrintsEveryUserWithPlaceholdersForEmptyFields(t *testing.T) {
	db := newTestDB(t)
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at, email, role, role_local) VALUES (?, ?, ?, ?, ?, ?)`,
		"user-1", "Friend", time.Now().UTC().Format(time.RFC3339), "friend@example.org", "admin", "admin",
	); err != nil {
		t.Fatalf("insert test user: %v", err)
	}
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		"user-2", "No Email Yet", time.Now().UTC().Format(time.RFC3339),
	); err != nil {
		t.Fatalf("insert second test user: %v", err)
	}

	var out bytes.Buffer
	if err := listUsers(db, &out); err != nil {
		t.Fatalf("listUsers() returned error: %v", err)
	}

	got := out.String()
	if !strings.Contains(got, "user-1\tFriend\tfriend@example.org\trole=admin\trole_local=admin\n") {
		t.Errorf("output = %q, want a line for user-1 with its email and role/role_local", got)
	}
	if !strings.Contains(got, "user-2\tNo Email Yet\t-\trole=\trole_local=-\n") {
		t.Errorf("output = %q, want a line for user-2 with \"-\" placeholders for the unset email and role_local", got)
	}
}

func TestListUsersReturnsErrorWhenListUsersFails(t *testing.T) {
	db := newTestDB(t)
	if _, err := db.Exec(`DROP TABLE users`); err != nil {
		t.Fatalf("drop users table: %v", err)
	}

	var out bytes.Buffer
	if err := listUsers(db, &out); err == nil {
		t.Fatal("listUsers() succeeded despite a missing users table, want an error")
	}
}
