// Command tesserarr runs the Tesserarr service.
//
// Subcommands:
//
//	serve             start the HTTP server
//	healthcheck       probe a locally running instance (used by the container health check)
//	selftest          check a running instance from the outside
//	invite create     mint a new invitation (see the package comment on "invite create" below)
//	user list         list every user's id, role and bound identities — find the id "user promote" needs
//	user remap        rebind a forward-auth identity from an old subject to a new one
//	user promote <id> <role>  set an operator role assignment for the user with this id
package main

import (
	"context"
	"database/sql"
	"errors"
	"flag"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"
	"unicode"

	"github.com/strausmann/tesserarr/internal/api"
	"github.com/strausmann/tesserarr/internal/config"
	"github.com/strausmann/tesserarr/internal/identity"
	"github.com/strausmann/tesserarr/internal/invite"
	"github.com/strausmann/tesserarr/internal/selftest"
	"github.com/strausmann/tesserarr/internal/store"
)

// version is stamped at build time via -ldflags.
var version = "dev"

func main() {
	if len(os.Args) < 2 {
		fmt.Fprintln(os.Stderr, "usage: tesserarr <serve|healthcheck|selftest|invite|user|version>")
		os.Exit(2)
	}

	var err error
	switch os.Args[1] {
	case "serve":
		err = runServe()
	case "healthcheck":
		err = runHealthcheck()
	case "selftest":
		err = runSelftest()
	case "invite":
		err = runInvite(os.Args[2:])
	case "user":
		err = runUser(os.Args[2:])
	case "version":
		fmt.Println(version)
		return
	default:
		fmt.Fprintf(os.Stderr, "unknown subcommand %q\n", os.Args[1])
		os.Exit(2)
	}

	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

func runServe() error {
	cfg, err := config.Load()
	if err != nil {
		return fmt.Errorf("configuration: %w", err)
	}

	st, err := store.Open(cfg.DBPath)
	if err != nil {
		return fmt.Errorf("database: %w", err)
	}
	defer func() { _ = st.Close() }()

	// Created here, ahead of api.New, rather than a fresh context.Background()
	// right before it: api.New's own network round trip against the OIDC
	// provider (identity.NewOIDCLogin's discovery request) should be
	// cancelable by the same signal this process already listens for below,
	// not run against a context nothing can ever cancel.
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	a, err := api.New(ctx, cfg, st)
	if err != nil {
		return fmt.Errorf("api: %w", err)
	}

	// Fail loudly at startup rather than shipping a misrouted endpoint. The
	// same check runs in the test suite; running it here too means a build that
	// somehow skipped the tests still cannot start with a broken route table.
	if problems := api.CheckInventory(a.Routes()); len(problems) > 0 {
		for _, p := range problems {
			fmt.Fprintln(os.Stderr, "route inventory:", p)
		}
		return errors.New("route inventory rejected the registered routes")
	}

	srv := &http.Server{
		Addr:              cfg.HTTPAddr,
		Handler:           a.Handler(),
		ReadHeaderTimeout: 10 * time.Second,
	}

	errCh := make(chan error, 1)
	go func() {
		fmt.Fprintf(os.Stderr, "tesserarr %s listening on %s\n", version, cfg.HTTPAddr)
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			errCh <- err
		}
	}()

	select {
	case err := <-errCh:
		return fmt.Errorf("http server: %w", err)
	case <-ctx.Done():
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		return srv.Shutdown(shutdownCtx)
	}
}

// runHealthcheck probes the local instance. The container health check calls
// this instead of curl, so the image does not need an HTTP client installed.
func runHealthcheck() error {
	cfg, err := config.Load()
	if err != nil {
		return fmt.Errorf("configuration: %w", err)
	}

	client := &http.Client{Timeout: 5 * time.Second}
	resp, err := client.Get("http://" + cfg.HTTPAddr + "/healthz")
	if err != nil {
		return fmt.Errorf("health check request: %w", err)
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("health check returned status %d", resp.StatusCode)
	}
	return nil
}

func runSelftest() error {
	cfg, err := config.Load()
	if err != nil {
		return fmt.Errorf("configuration: %w", err)
	}

	problems := selftest.Run(cfg.BaseURL, nil)
	for _, p := range problems {
		fmt.Fprintln(os.Stderr, "selftest:", p)
	}
	if len(problems) > 0 {
		return fmt.Errorf("selftest found %d problem(s)", len(problems))
	}
	fmt.Println("selftest passed")
	return nil
}

// runInvite dispatches the "invite" subcommands. It exists as its own
// function, separate from main's switch, purely so tests can drive the
// dispatch logic (unknown/missing sub-subcommand) without an os.Exit in the
// way.
func runInvite(args []string) error {
	if len(args) == 0 {
		return errors.New("usage: tesserarr invite create [-ttl=<duration>]")
	}
	switch args[0] {
	case "create":
		return runInviteCreate(args[1:])
	default:
		return fmt.Errorf("unknown invite subcommand %q", args[0])
	}
}

// runInviteCreate is "invite create": the operator's way to bootstrap access.
//
// ADR-0004 makes an invitation the sole path to an account — there is no
// self-registration. That includes the very first account. Nothing in the
// HTTP surface can mint that first invitation: every endpoint of consequence
// sits behind the session check (see internal/api/router.go), and the
// session check is unimplemented until an account already exists. Without a
// way around that, no one could ever reach the app at all. Rather than
// carving a bootstrap exception into the HTTP surface — which would mean a
// second, permanently reachable way to mint invitations, right where the
// public internet can find it — this reaches for the same access an
// operator already has by definition (running "docker exec" against their
// own container) and stops there.
//
// The actual minting is done by createInvite; runInviteCreate only wires up
// the real environment (flags, configuration, the on-disk database) around
// it.
func runInviteCreate(args []string) error {
	fs := flag.NewFlagSet("invite create", flag.ContinueOnError)
	ttl := fs.Duration("ttl", 0, "invitation lifetime (default: "+invite.DefaultTTL.String()+")")
	if err := fs.Parse(args); err != nil {
		return err
	}

	cfg, err := config.Load()
	if err != nil {
		return fmt.Errorf("configuration: %w", err)
	}

	st, err := store.Open(cfg.DBPath)
	if err != nil {
		return fmt.Errorf("database: %w", err)
	}
	defer func() { _ = st.Close() }()

	return createInvite(st.DB(), cfg.BaseURL, *ttl, cfg.ForwardAuthEnabled, os.Stdout)
}

// createInvite mints one invitation and writes the plaintext token and the
// full redeem link to out.
//
// This is the only moment the plaintext token exists outside the database
// (see the package comment on invite.Create) — from here it goes straight to
// out and nowhere else: no log line, no error message ever repeats it. out
// is deliberately the caller's stdout, never stderr — stderr is where this
// binary's own error/logging output goes (see runServe, runHealthcheck), and
// mixing a secret into that stream risks it reaching a log collector that
// nothing else printed here does.
//
// It is split out from runInviteCreate so it can be tested against a
// temporary, migrated database without going through config.Load (which
// reads real process environment variables) — see main_test.go.
//
// forwardAuthEnabled controls whether a SECOND link — one that redeems the
// same token through LoginForwardAuthPath instead of the OIDC redeem flow —
// is printed alongside the ordinary one. Passed in rather than read from
// config here, for the same testability reason ttl and baseURL already are:
// main_test.go exercises both the true and false case directly, without a
// real forward-auth configuration.
func createInvite(db *sql.DB, baseURL string, ttl time.Duration, forwardAuthEnabled bool, out io.Writer) error {
	token, err := invite.Create(db, ttl)
	if err != nil {
		return fmt.Errorf("create invite: %w", err)
	}

	base := strings.TrimRight(baseURL, "/")
	link := base + "/invite/" + token
	// Errors from writing to out are deliberately ignored: out is the
	// caller's stdout (see runInviteCreate), and there is no more-honest
	// place left to report a broken stdout to.
	_, _ = fmt.Fprintf(out, "token:       %s\n", token)
	_, _ = fmt.Fprintf(out, "redeem link: %s\n", link)
	if forwardAuthEnabled {
		forwardAuthLink := base + api.LoginForwardAuthPath + "?invite=" + token
		_, _ = fmt.Fprintf(out, "redeem link (forward-auth): %s\n", forwardAuthLink)
	}
	_, _ = fmt.Fprintln(out, "Until it is redeemed, this link IS the credential — hand it to your friend over a channel you trust, not a public one.")
	return nil
}

// errUsage is the sentinel behind every "user" subcommand's argument-count
// rejection. A plain errors.New would leave TestRunUserRemapRejectsWrongArgumentCount
// and TestRunUserPromoteRejectsWrongArgumentCount unable to tell "the guard
// fired" apart from "config.Load failed for its own, unrelated reason" —
// both return SOME non-nil error either way, and asserting only err != nil
// passes in both cases (found live: the guard was deleted entirely and the
// test stayed green because the test process has no TESSERARR_BASE_URL set,
// so config.Load fails on its own right after). errors.Is(err, errUsage)
// closes that gap: config.Load's error can never satisfy it.
var errUsage = errors.New("usage")

// runUser dispatches the "user" subcommands — mirroring runInvite's shape
// exactly, for the same reason: tests drive the dispatch logic without an
// os.Exit in the way.
func runUser(args []string) error {
	if len(args) == 0 {
		return fmt.Errorf("%w: tesserarr user list | tesserarr user remap <old-subject> <new-subject> | tesserarr user promote <id> <role>", errUsage)
	}
	switch args[0] {
	case "list":
		return runUserList(args[1:])
	case "remap":
		return runUserRemap(args[1:])
	case "promote":
		return runUserPromote(args[1:])
	default:
		return fmt.Errorf("unknown user subcommand %q", args[0])
	}
}

// runUserList is "user list": the operator's way to find the id "user
// promote" now requires — see PromoteUserRole's own comment (admin.go) for
// why promoting by email was unsafe and this replaced it. The actual
// formatting is done by listUsers, split out the same way createInvite is
// split out from runInviteCreate: so it can be tested against a temporary,
// migrated database without going through config.Load.
func runUserList(args []string) error {
	if len(args) != 0 {
		return fmt.Errorf("%w: user list takes no arguments", errUsage)
	}

	cfg, err := config.Load()
	if err != nil {
		return fmt.Errorf("configuration: %w", err)
	}
	st, err := store.Open(cfg.DBPath)
	if err != nil {
		return fmt.Errorf("database: %w", err)
	}
	defer func() { _ = st.Close() }()

	return listUsers(st.DB(), os.Stdout)
}

// listUsers prints one line per user — id, display name, an EXPLICITLY
// unverified email, the CURRENTLY EFFECTIVE role, the operator's own
// role_local assignment, and the (provider, subject) identities actually
// bound to the account — to out. A blank email or role_local prints as
// "-", not an empty column that would be easy to misread as a parsing
// error.
//
// The email column is labeled "email(unverified)" and, when its value is
// shared with another account in THIS listing, additionally flagged — see
// identity.UserSummary.Email's own comment for why: users.email carries no
// uniqueness constraint, and internal/api's account handler lets any
// signed-in user set it to any string, including one somebody else already
// claims. An operator picking an id to hand to "tesserarr user promote"
// off of a matching email alone could promote the wrong account entirely.
// Neither the label nor the flag stops that from being possible — only a
// schema-level UNIQUE constraint would (tracked separately, see the
// comment this replaced) — but they stop the collision from being
// invisible in the one place an operator is actually looking when they
// decide who to promote. The identities column is what an operator SHOULD
// use instead to confirm which row belongs to which real person: neither
// its provider nor its subject value can be set by the account holder
// themselves (identity.IdentitySummary's own comment).
//
// Every field printed below goes through sanitizeForLine first — not only
// email. handleUpdateAccount (internal/api/account.go) rejects control
// characters in a NEWLY submitted email, but that check has nothing to say
// about rows written before it existed, and display_name /
// identities.subject reach this function from a forward-auth guard header
// or an OIDC provider's own claims (identity.UserSummary's field
// comments), sources this package has no reason to assume are free of
// control characters either. Without sanitizeForLine, a single crafted
// field could inject a CR+LF pair that makes one database row print as
// two terminal lines — one of them entirely fabricated, e.g. claiming
// role=admin for an id that does not exist — which is exactly the kind of
// forgery an operator relying on "one line per account" (this function's
// own doc comment, above) to decide who to promote must never see.
func listUsers(db *sql.DB, out io.Writer) error {
	users, err := identity.ListUsers(db)
	if err != nil {
		return fmt.Errorf("list users: %w", err)
	}

	// emailCounts powers the shared-email flag below — computed across the
	// WHOLE listing, from the RAW (unsanitized) email, before printing any
	// line, so a collision is flagged on every row it involves regardless
	// of which one happens to print first. Grouping on the raw value
	// rather than its sanitized form keeps this comparison exactly as
	// precise as before sanitizeForLine existed; sanitizing is a
	// presentation concern applied only when a field is about to be
	// written to out, below.
	emailCounts := make(map[string]int, len(users))
	for _, u := range users {
		if u.Email != "" {
			emailCounts[u.Email]++
		}
	}

	for _, u := range users {
		email := u.Email
		switch {
		case email == "":
			email = "-"
		case emailCounts[email] > 1:
			email += " (shared with another account below — do not use to identify who this is)"
		}
		roleLocal := u.RoleLocal
		if roleLocal == "" {
			roleLocal = "-"
		}
		identities := "-"
		if len(u.Identities) > 0 {
			parts := make([]string, len(u.Identities))
			for i, id := range u.Identities {
				parts[i] = sanitizeForLine(id.Provider) + ":" + sanitizeForLine(id.Subject)
			}
			identities = strings.Join(parts, ",")
		}
		// Errors from writing to out are deliberately ignored, matching
		// createInvite's own reasoning: out is the caller's stdout, and
		// there is no more-honest place left to report a broken stdout to.
		_, _ = fmt.Fprintf(out, "%s\t%s\temail(unverified)=%s\trole=%s\trole_local=%s\tidentities=%s\n",
			sanitizeForLine(u.ID), sanitizeForLine(u.DisplayName), sanitizeForLine(email), sanitizeForLine(u.Role), sanitizeForLine(roleLocal), identities)
	}
	return nil
}

// sanitizeForLine makes s safe to appear inside one tab-separated line of
// listUsers' output. Every rune unicode.IsControl reports true for — the
// C0 set (0x00-0x1F, which includes CR and LF, the two bytes that would
// otherwise let a single field masquerade as multiple lines or extra
// tab-separated columns) plus DEL and the C1 set (0x7F, 0x80-0x9F, which
// includes ESC-led ANSI/VT100 escape sequences a terminal would otherwise
// interpret) — is replaced with its \xHH hex escape.
//
// Escaping rather than stripping is deliberate: stripping a CR+LF pair
// that was clearly trying to forge a second line would leave the tampering
// invisible, printing what looks like an ordinary, untouched field. The
// \xHH form stays exactly as long as the byte it replaces (one input rune
// in this alphabet, since every value unicode.IsControl accepts here fits
// two hex digits), reads unambiguously as "not the real character", and
// can never itself be mistaken for a delimiter or trigger further
// escape-sequence interpretation.
func sanitizeForLine(s string) string {
	var b strings.Builder
	for _, r := range s {
		if unicode.IsControl(r) {
			fmt.Fprintf(&b, `\x%02x`, r)
			continue
		}
		b.WriteRune(r)
	}
	return b.String()
}

func runUserRemap(args []string) error {
	if len(args) != 2 {
		return fmt.Errorf("%w: user remap expects exactly 2 arguments (old-subject new-subject)", errUsage)
	}

	cfg, err := config.Load()
	if err != nil {
		return fmt.Errorf("configuration: %w", err)
	}
	st, err := store.Open(cfg.DBPath)
	if err != nil {
		return fmt.Errorf("database: %w", err)
	}
	defer func() { _ = st.Close() }()

	if err := identity.RemapForwardAuthSubject(st.DB(), args[0], args[1]); err != nil {
		if errors.Is(err, identity.ErrSubjectAlreadyBound) {
			return fmt.Errorf("remap user: the new subject %q is already bound to a different account — pick a different value, or reconcile the two accounts by hand first", args[1])
		}
		return fmt.Errorf("remap user: %w", err)
	}
	fmt.Println("remapped.")
	return nil
}

// runUserPromote is "user promote": the operator's way to hand out a
// role_local assignment. args[0] is a user's id — the value "tesserarr
// user list" prints — NOT an email; see PromoteUserRole's own comment
// (internal/identity/admin.go) for why an earlier version of this command
// that took an email was unsafe. An id that does not exist (including a
// stale email typed out of habit — no id ever looks like one) fails loud
// with ErrUserNotFound below, never silently promoting the wrong account.
func runUserPromote(args []string) error {
	if len(args) != 2 {
		return fmt.Errorf(`%w: user promote expects exactly 2 arguments (id role) — run "tesserarr user list" to find the id`, errUsage)
	}

	cfg, err := config.Load()
	if err != nil {
		return fmt.Errorf("configuration: %w", err)
	}
	st, err := store.Open(cfg.DBPath)
	if err != nil {
		return fmt.Errorf("database: %w", err)
	}
	defer func() { _ = st.Close() }()

	if err := identity.PromoteUserRole(st.DB(), args[0], args[1]); err != nil {
		return fmt.Errorf("promote user: %w", err)
	}
	fmt.Println("promoted.")
	return nil
}
