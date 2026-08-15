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
	if !strings.Contains(got, "user-1\tFriend\temail(unverified)=friend@example.org\trole=admin\trole_local=admin\tidentities=0\n") {
		t.Errorf("output = %q, want a line for user-1 with its email and role/role_local", got)
	}
	if !strings.Contains(got, "user-2\tNo Email Yet\temail(unverified)=-\trole=\trole_local=-\tidentities=0\n") {
		t.Errorf("output = %q, want a line for user-2 with \"-\" placeholders for the unset email/role_local, but a real 0 count for identities (a count, not an unset field — see listUsers' own doc comment)", got)
	}
}

// TestListUsersFlagsEmailSharedBetweenAccounts proves the "shared with
// another account" marker listUsers' own doc comment describes: two users
// with the SAME users.email (unenforced — see
// identity.UserSummary.Email's own comment on why this can happen at all)
// both get it, a third user with a unique email does not.
func TestListUsersFlagsEmailSharedBetweenAccounts(t *testing.T) {
	db := newTestDB(t)
	for _, u := range []struct{ id, email string }{
		{"user-1", "shared@example.org"},
		{"user-2", "shared@example.org"},
		{"user-3", "unique@example.org"},
	} {
		if _, err := db.Exec(
			`INSERT INTO users (id, display_name, created_at, email) VALUES (?, ?, ?, ?)`,
			u.id, "Test User", time.Now().UTC().Format(time.RFC3339), u.email,
		); err != nil {
			t.Fatalf("insert test user %s: %v", u.id, err)
		}
	}

	var out bytes.Buffer
	if err := listUsers(db, &out); err != nil {
		t.Fatalf("listUsers() returned error: %v", err)
	}

	got := out.String()
	for _, id := range []string{"user-1", "user-2"} {
		line := lineForUser(t, got, id)
		if !strings.Contains(line, "shared@example.org (shared with another account below") {
			t.Errorf("%s line = %q, want the shared-email marker", id, line)
		}
	}
	line3 := lineForUser(t, got, "user-3")
	if strings.Contains(line3, "shared with another account") {
		t.Errorf("user-3 line = %q, want NO shared-email marker (its email is unique in this listing)", line3)
	}
}

// TestListUsersPrintsIdentitiesColumn proves listUsers prints both halves
// of the identity representation: the account's own line states the
// COUNT of bound identities (identities=1, a plain digit derived from
// len(), see listUsers' own doc comment), and each identity gets its own
// further line beneath it, giving the (provider, subject) pair an
// operator can actually trust, unlike the unverified email column (see
// identity.IdentitySummary's own comment). This changed from a single
// comma-joined "identities=forward-auth:sub-123" field on the account's
// own line — see TestListUsersSubjectCannotForgeAnIdentityLine below for
// why that joined form was unsafe; this test only needs updating for the
// new, equally simple single-identity shape, not for any new assurance.
func TestListUsersPrintsIdentitiesColumn(t *testing.T) {
	db := newTestDB(t)
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		"user-1", "Friend", time.Now().UTC().Format(time.RFC3339),
	); err != nil {
		t.Fatalf("insert test user: %v", err)
	}
	if _, err := db.Exec(
		`INSERT INTO identities (user_id, provider, subject) VALUES (?, ?, ?)`,
		"user-1", "forward-auth", "sub-123",
	); err != nil {
		t.Fatalf("insert test identity: %v", err)
	}

	var out bytes.Buffer
	if err := listUsers(db, &out); err != nil {
		t.Fatalf("listUsers() returned error: %v", err)
	}
	got := out.String()

	if !strings.Contains(got, "\tidentities=1\n") {
		t.Errorf("output = %q, want the account line to state identities=1", got)
	}
	if !strings.Contains(got, "user-1\tidentity\tprovider=forward-auth\tsubject=sub-123\temail=-\temail_verified=unknown\n") {
		t.Errorf("output = %q, want a separate identity line for user-1's forward-auth:sub-123 identity, with the email column defaulting to \"-\" (no email was inserted) and email_verified defaulting to \"unknown\" (forward-auth carries no verification signal at all — see identity.EmailVerification's own comment)", got)
	}
}

// TestListUsersPrintsAndEscapesIdentityEmail is TestListUsersPrintsIdentitiesColumn's
// counterpart for a NON-empty identities.email: proves the value actually
// reaches the identity line (not just that the "-" placeholder does, which
// the test above already covers), that it lands under its own "email="
// column — never confused with the account line's own "email(unverified)="
// column, see this file's TestListUsersEscapesControlCharactersInEveryField
// doc comment for why that distinction matters to an operator deciding who
// to promote — and that it goes through sanitizeForLine exactly like every
// other field, even though it comes from a trusted source (see
// identity.IdentitySummary.Email's own comment: trusted-source does not
// mean format-guaranteed OR provider-confirmed — a self-hosted OIDC
// provider can still hand back anything in its email claim, and this
// fixture never inserts a value for email_verified at all, so that column
// reads "unknown", not "true", even though the email itself came from a
// trusted source).
func TestListUsersPrintsAndEscapesIdentityEmail(t *testing.T) {
	db := newTestDB(t)
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		"user-1", "Friend", time.Now().UTC().Format(time.RFC3339),
	); err != nil {
		t.Fatalf("insert test user: %v", err)
	}
	const taintedEmail = "verified@example.org\r\nfake-admin\tForged Admin\temail(unverified)=-\trole=admin"
	if _, err := db.Exec(
		`INSERT INTO identities (user_id, provider, subject, email) VALUES (?, ?, ?, ?)`,
		"user-1", "oidc", "sub-123", taintedEmail,
	); err != nil {
		t.Fatalf("insert test identity: %v", err)
	}

	var out bytes.Buffer
	if err := listUsers(db, &out); err != nil {
		t.Fatalf("listUsers() returned error: %v", err)
	}
	got := out.String()

	// The structural invariant, same as TestListUsersEscapesControlCharactersInEveryField:
	// exactly one line per row inserted (the account's own line plus this
	// one identity line), never more — a raw CR/LF in identities.email must
	// not forge an extra line.
	lines := strings.Split(strings.TrimRight(got, "\n"), "\n")
	if len(lines) != 2 {
		t.Fatalf("listUsers() printed %d lines, want exactly 2 (the account line and one identity line):\n%s", len(lines), got)
	}
	if strings.Contains(got, "fake-admin\tForged Admin") {
		t.Errorf("output = %q, contains the forged row as a real line", got)
	}
	if strings.ContainsRune(got, '\r') {
		t.Errorf("output = %q, contains a raw carriage return", got)
	}

	identityLines := identityLinesForUser(t, got, "user-1")
	if len(identityLines) != 1 {
		t.Fatalf("listUsers() printed %d identity lines for user-1, want exactly 1:\n%s", len(identityLines), got)
	}
	// Built by concatenation, not a single raw string literal: the trailing
	// "\temail_verified=unknown" needs an ACTUAL tab byte (the real column
	// separator listUsers prints), whereas everything inside the backtick
	// literal is the LITERAL four-character sequence \x0d etc. sanitizeForLine
	// writes for a real control byte — mixing the two inside one backtick
	// string would silently turn the intended tab into two literal
	// characters instead.
	wantIdentityLine := `email=verified@example.org\x0d\x0afake-admin\x09Forged Admin\x09email(unverified)=-\x09role=admin` + "\temail_verified=unknown"
	if !strings.Contains(identityLines[0], wantIdentityLine) {
		t.Errorf("identity line = %q, want the tainted email escaped (\\x0d\\x0a for CR+LF, \\x09 for the tab bytes trying to forge extra columns) and printed under email=, followed by email_verified=unknown (no email_verified value was inserted)", identityLines[0])
	}
}

// TestListUsersPrintsZeroIdentitiesAsARealCount is the regression test for
// the bug this test accompanies fixing: an account with NO bound identities
// used to print identities=- (the same placeholder email/role_local use for
// an unset field), which reads as "not recorded" rather than what it
// actually is — a real, meaningful count of zero. Zero identities is
// precisely the case where an operator deciding who to promote (see
// runUserPromote's own comment) cannot verify who this account belongs to
// at all; hiding that behind a placeholder is the opposite of what the
// column is for. identities= must always be len(u.Identities) formatted as
// a plain decimal digit string, per listUsers' own doc comment, including
// when that length is 0 — never "-".
func TestListUsersPrintsZeroIdentitiesAsARealCount(t *testing.T) {
	db := newTestDB(t)
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		"user-1", "No Identities Yet", time.Now().UTC().Format(time.RFC3339),
	); err != nil {
		t.Fatalf("insert test user: %v", err)
	}
	// Deliberately no row in identities for user-1.

	var out bytes.Buffer
	if err := listUsers(db, &out); err != nil {
		t.Fatalf("listUsers() returned error: %v", err)
	}
	got := out.String()

	if !strings.Contains(got, "\tidentities=0\n") {
		t.Errorf("output = %q, want the account line to state identities=0 for an account with no bound identities, not a \"-\" placeholder", got)
	}
	if strings.Contains(got, "\tidentities=-\n") {
		t.Errorf("output = %q, must NOT print identities=- (that reads as \"not recorded\", not the real count of 0)", got)
	}
}

// TestListUsersSubjectCannotForgeAnIdentityLine is the regression test for
// the vulnerability the one-line-per-identity design (listUsers' own doc
// comment) exists to close: a Subject value containing the OLD list
// separator (',') must never change how many identity lines print, nor
// make the account appear to own a (provider, subject) pair it does not
// actually have bound. This is not a contrived input — a comma is
// entirely legal in a value this package receives verbatim from a
// forward-auth guard header or an OIDC provider's own claims
// (identity.IdentitySummary's own comment); nothing upstream of listUsers
// rejects it.
//
// forgedSubject reads, at a glance, like TWO (provider, subject) pairs —
// the account's own real "forward-auth:mine" identity, followed by what
// looks like a second, fabricated "oidc:victim" entry falsely claiming
// this account owns somebody else's OIDC subject. Under the OLD
// comma-joined design this printed as a single field,
// identities=forward-auth:mine,oidc:victim, indistinguishable from two
// real identities — an operator deciding who to promote could be shown a
// binding that was never in the database.
func TestListUsersSubjectCannotForgeAnIdentityLine(t *testing.T) {
	db := newTestDB(t)
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		"user-1", "Attacker", time.Now().UTC().Format(time.RFC3339),
	); err != nil {
		t.Fatalf("insert test user: %v", err)
	}
	const forgedSubject = "mine,oidc:victim"
	if _, err := db.Exec(
		`INSERT INTO identities (user_id, provider, subject) VALUES (?, ?, ?)`,
		"user-1", "forward-auth", forgedSubject,
	); err != nil {
		t.Fatalf("insert test identity: %v", err)
	}

	var out bytes.Buffer
	if err := listUsers(db, &out); err != nil {
		t.Fatalf("listUsers() returned error: %v", err)
	}
	got := out.String()

	// The structural invariant: exactly one identity LINE, matching the
	// one row actually inserted above. This is driven by len(u.Identities)
	// — an actual Go slice length — never by re-parsing the comma inside
	// forgedSubject, so no content of that field can move this count.
	lines := identityLinesForUser(t, got, "user-1")
	if len(lines) != 1 {
		t.Fatalf("listUsers() printed %d identity lines for user-1, want exactly 1 (one real identity row was inserted):\n%s", len(lines), got)
	}
	if lines[0] != "user-1\tidentity\tprovider=forward-auth\tsubject="+forgedSubject+"\temail=-\temail_verified=unknown" {
		t.Errorf("identity line = %q, want the whole comma-containing subject preserved as ONE field, not split at the comma", lines[0])
	}

	// The account's own line must agree: identities=1 (one real row), a
	// plain digit from len(), never inflated to identities=2 by content.
	if !strings.Contains(got, "\tidentities=1\n") {
		t.Errorf("output = %q, want the account line to state identities=1, not a count inflated by the comma in its subject", got)
	}

	// No line may claim the fabricated "oidc"/"victim" pair as its own,
	// separate provider/subject fields — the exact line-count check above
	// already rules this out structurally, this is the same property
	// stated the way an operator would actually notice it going wrong.
	if strings.Contains(got, "provider=oidc\tsubject=victim") {
		t.Errorf("output = %q, contains a forged oidc:victim identity", got)
	}
}

// TestListUsersSubjectColonCannotBeMisreadAsProviderBoundary proves the
// second, lower-severity half of the same design change: an identity's
// provider and subject are no longer joined with ':' on their line
// (listUsers' own doc comment explains why that boundary is not reliable
// either, once a Subject can contain one), so a colon inside subject can
// no longer be misread as marking where the provider ends and the
// subject begins.
func TestListUsersSubjectColonCannotBeMisreadAsProviderBoundary(t *testing.T) {
	db := newTestDB(t)
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		"user-1", "Friend", time.Now().UTC().Format(time.RFC3339),
	); err != nil {
		t.Fatalf("insert test user: %v", err)
	}
	if _, err := db.Exec(
		`INSERT INTO identities (user_id, provider, subject) VALUES (?, ?, ?)`,
		"user-1", "oidc", "abc:def",
	); err != nil {
		t.Fatalf("insert test identity: %v", err)
	}

	var out bytes.Buffer
	if err := listUsers(db, &out); err != nil {
		t.Fatalf("listUsers() returned error: %v", err)
	}
	got := out.String()

	lines := identityLinesForUser(t, got, "user-1")
	if len(lines) != 1 {
		t.Fatalf("listUsers() printed %d identity lines for user-1, want exactly 1:\n%s", len(lines), got)
	}
	if lines[0] != "user-1\tidentity\tprovider=oidc\tsubject=abc:def\temail=-\temail_verified=unknown" {
		t.Errorf("identity line = %q, want provider=oidc and subject=abc:def as separate, unambiguous fields", lines[0])
	}
}

// identityLinesForUser returns every identity line (the
// "<id>\tidentity\tprovider=...\tsubject=...\temail=...\temail_verified=...\n" lines
// listUsers prints below an account's own line) belonging to userID, trimmed
// of their trailing newline. Matching on the id+"\tidentity\t" prefix — rather
// than lineForUser's plain id+"\t" prefix, which an account's own line
// also starts with — is what keeps this helper from conflating the two
// line kinds when a user has both.
func identityLinesForUser(t *testing.T, output, userID string) []string {
	t.Helper()
	prefix := userID + "\tidentity\t"
	var lines []string
	for _, line := range strings.Split(strings.TrimRight(output, "\n"), "\n") {
		if strings.HasPrefix(line, prefix) {
			lines = append(lines, line)
		}
	}
	return lines
}

// TestListUsersEscapesControlCharactersInEveryField proves that no field
// listUsers prints — not just email — can forge an extra line or otherwise
// break the "one line per account" invariant an operator relies on to pick
// who to promote (see runUserPromote's own comment). users.email reaches
// this function entirely unvalidated for control characters from at least
// one path an attacker fully controls (internal/api's handleUpdateAccount,
// any signed-in user, any string up to 254 bytes — see UserSummary.Email's
// comment), and display_name/identities.subject arrive from a forward-auth
// guard header or an OIDC provider's own claims, which this package does
// not treat as free of control characters either. A CR+LF pair inside
// user-1's email that spells out what looks like an entire second,
// higher-privileged row must NOT turn into a real second line, and an ESC
// byte must never reach the operator's terminal raw.
func TestListUsersEscapesControlCharactersInEveryField(t *testing.T) {
	db := newTestDB(t)
	forgedLine := "attacker@example.org\r\nfake-admin\tForged Admin\temail(unverified)=-\trole=admin\trole_local=admin\tidentities=-"
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at, email) VALUES (?, ?, ?, ?)`,
		"user-1", "Attacker\x1b[31m", time.Now().UTC().Format(time.RFC3339), forgedLine,
	); err != nil {
		t.Fatalf("insert test user: %v", err)
	}
	if _, err := db.Exec(
		`INSERT INTO users (id, display_name, created_at) VALUES (?, ?, ?)`,
		"user-2", "Second User", time.Now().UTC().Format(time.RFC3339),
	); err != nil {
		t.Fatalf("insert second test user: %v", err)
	}

	var out bytes.Buffer
	if err := listUsers(db, &out); err != nil {
		t.Fatalf("listUsers() returned error: %v", err)
	}
	got := out.String()

	// The structural invariant: exactly one line per row inserted above,
	// never more. A raw CR or LF embedded in a field would grow this count
	// past 2 — that is the forgery this test exists to catch.
	lines := strings.Split(strings.TrimRight(got, "\n"), "\n")
	if len(lines) != 2 {
		t.Fatalf("listUsers() printed %d lines, want exactly 2 (one per account):\n%s", len(lines), got)
	}
	for _, id := range []string{"user-1", "user-2"} {
		lineForUser(t, got, id) // fails the test itself if not exactly one match
	}
	if strings.Contains(got, "fake-admin\tForged Admin") {
		t.Errorf("output = %q, contains the forged row as a real line", got)
	}

	// No raw control byte may reach the output — neither the CR/LF pair
	// that tried to forge a line, nor the ANSI escape byte in the display
	// name.
	if strings.ContainsRune(got, '\r') {
		t.Errorf("output = %q, contains a raw carriage return", got)
	}
	if strings.ContainsRune(got, '\x1b') {
		t.Errorf("output = %q, contains a raw ESC byte", got)
	}
	// Every legitimate line-ending '\n' is accounted for by the 2 rows
	// above; strings.Split already proved that. What's left to prove is
	// that the tampering is still VISIBLE rather than silently dropped —
	// an operator should see that user-1's email was tampered with, not a
	// blank or truncated field.
	if !strings.Contains(got, `\x0d`) || !strings.Contains(got, `\x0a`) {
		t.Errorf("output = %q, want the escaped \\x0d and \\x0a for the CR/LF that was in user-1's email", got)
	}
	if !strings.Contains(got, `\x1b`) {
		t.Errorf("output = %q, want the escaped \\x1b for the ESC byte that was in user-1's display name", got)
	}
}

// lineForUser returns the single line of listUsers' output starting with
// userID's id column, failing the test if there is not exactly one.
func lineForUser(t *testing.T, output, userID string) string {
	t.Helper()
	var found string
	count := 0
	for _, line := range strings.Split(strings.TrimRight(output, "\n"), "\n") {
		if strings.HasPrefix(line, userID+"\t") {
			found = line
			count++
		}
	}
	if count != 1 {
		t.Fatalf("output has %d lines starting with %q, want exactly 1:\n%s", count, userID+"\t", output)
	}
	return found
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
