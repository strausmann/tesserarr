package selftest

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestRunPassesAgainstHealthyServer(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/healthz":
			w.WriteHeader(http.StatusOK)
		case "/api/v1/app/session":
			// Session path without a cookie must reject.
			w.WriteHeader(http.StatusUnauthorized)
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	t.Cleanup(srv.Close)

	if errs := Run(srv.URL, srv.Client()); len(errs) != 0 {
		t.Errorf("Run() reported %d problems, want none: %v", len(errs), errs)
	}
}

func TestRunFailsWhenHealthIsDown(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	t.Cleanup(srv.Close)

	if errs := Run(srv.URL, srv.Client()); len(errs) == 0 {
		t.Fatal("Run() reported no problem, want one about the health path")
	}
}

func TestRunFailsWhenSessionPathIsOpen(t *testing.T) {
	// A session-checked path answering 200 without a cookie is the failure this
	// check exists for.
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	t.Cleanup(srv.Close)

	if errs := Run(srv.URL, srv.Client()); len(errs) == 0 {
		t.Fatal("Run() reported no problem, want one about the open session path")
	}
}

func TestRunFailsWhenServerUnreachable(t *testing.T) {
	// Port 1 on the loopback interface: nothing listens there.
	if errs := Run("http://127.0.0.1:1", http.DefaultClient); len(errs) == 0 {
		t.Fatal("Run() reported no problem, want one about the unreachable server")
	}
}
