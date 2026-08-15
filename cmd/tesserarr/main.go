// Command tesserarr runs the Tesserarr service.
//
// Subcommands:
//
//	serve         start the HTTP server
//	healthcheck   probe a locally running instance (used by the container health check)
//	selftest      check a running instance from the outside
//	invite create mint a new invitation (see the package comment on "invite create" below)
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
		// ReadTimeout bounds reading the REQUEST BODY, which
		// ReadHeaderTimeout alone does not cover (net/http.Server's own
		// doc comment on ReadHeaderTimeout: it "does not let Handlers make
		// per-request decisions on each request body's acceptable ...
		// timeout"). Without it, a caller that opens a connection and then
		// drip-feeds bytes at an arbitrarily slow rate holds a goroutine
		// (and, for a session-space route, a database connection borrowed
		// for the request's duration) open indefinitely — independent of
		// and in addition to the per-handler size guard in
		// internal/api/middleware.go's maxRequestBodyBytes, which bounds
		// HOW MUCH a caller may send, not how slowly. 15s gives noticeably
		// more headroom than the 10s header phase for a body capped at 4
		// KiB (maxRequestBodyBytes) — legitimate requests finish in
		// milliseconds; this only ever fires against a caller stalling on
		// purpose.
		ReadTimeout: 15 * time.Second,
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
		return fmt.Errorf("%w: tesserarr user remap <old-subject> <new-subject> | tesserarr user promote <email> <role>", errUsage)
	}
	switch args[0] {
	case "remap":
		return runUserRemap(args[1:])
	case "promote":
		return runUserPromote(args[1:])
	default:
		return fmt.Errorf("unknown user subcommand %q", args[0])
	}
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

func runUserPromote(args []string) error {
	if len(args) != 2 {
		return fmt.Errorf("%w: user promote expects exactly 2 arguments (email role)", errUsage)
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
