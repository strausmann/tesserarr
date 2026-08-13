// Command tesserarr runs the Tesserarr service.
//
// Subcommands:
//
//	serve        start the HTTP server
//	healthcheck  probe a locally running instance (used by the container health check)
//	selftest     check a running instance from the outside
package main

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/strausmann/tesserarr/internal/api"
	"github.com/strausmann/tesserarr/internal/config"
	"github.com/strausmann/tesserarr/internal/selftest"
	"github.com/strausmann/tesserarr/internal/store"
)

// version is stamped at build time via -ldflags.
var version = "dev"

func main() {
	if len(os.Args) < 2 {
		fmt.Fprintln(os.Stderr, "usage: tesserarr <serve|healthcheck|selftest|version>")
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

	a := api.New(cfg, st)

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

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

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
