package api

import (
	"database/sql"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"testing"

	"github.com/strausmann/tesserarr/internal/config"
	"github.com/strausmann/tesserarr/internal/invite"
)

// newForwardAuthTestAPI builds a full API exactly like newTestAPI, plus
// forward-auth enabled with a fixed, known configuration — a trusted
// /16, a known shared secret, the pangolin header preset. Individual tests
// override cfg fields on the returned *API directly (cfg is a pointer, so
// mutating a.cfg after construction is visible to every handler) where
// they need a DIFFERENT configuration, e.g. an untrusted network.
func newForwardAuthTestAPI(t *testing.T) *API {
	t.Helper()
	a := newTestAPI(t)
	a.cfg.ForwardAuthEnabled = true
	a.cfg.ForwardAuthSharedSecret = config.Secret("shared-secret-value")
	a.cfg.ForwardAuthHeaders = config.ForwardAuthHeaders{
		Subject:  "Remote-User-Id",
		Username: "Remote-User",
		Name:     "Remote-Name",
		Email:    "Remote-Email",
		Role:     "Remote-Role",
	}
	a.cfg.TrustedProxies = []netip.Prefix{netip.MustParsePrefix("172.20.0.0/16")}
	return a
}

func forwardAuthRequest(path, subject, secret string) *http.Request {
	req := httptest.NewRequest(http.MethodGet, path, nil)
	req.RemoteAddr = "172.20.0.5:54321"
	if secret != "" {
		req.Header.Set(ForwardAuthSecretHeaderName, secret)
	}
	if subject != "" {
		req.Header.Set("Remote-User-Id", subject)
	}
	return req
}

// Verifikationspunkt 1: keine korrekte Freigabe ohne das gemeinsame
// Geheimnis, selbst mit einer perfekt gefälschten Identitäts-Kopfzeile.
func TestHandleLoginForwardAuthRejectsMissingSharedSecret(t *testing.T) {
	a := newForwardAuthTestAPI(t)
	req := forwardAuthRequest(LoginForwardAuthPath, "admin-subject", "")

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusForbidden {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusForbidden)
	}
	if findSetCookie(rec.Result(), a.cfg.SessionCookieName) != nil {
		t.Error("a session cookie was set despite the missing shared secret")
	}
}

// Verifikationspunkt 2: keine Freigabe aus einem nicht vertrauenswürdigen
// Netz, selbst mit dem korrekten Geheimnis.
func TestHandleLoginForwardAuthRejectsUntrustedNetwork(t *testing.T) {
	a := newForwardAuthTestAPI(t)
	req := forwardAuthRequest(LoginForwardAuthPath, "admin-subject", "shared-secret-value")
	req.RemoteAddr = "10.0.0.9:1234"

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusForbidden {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusForbidden)
	}
}

// Verifikationspunkt 3: unbekannter Nutzer ohne Einladungs-Kennzeichen in
// der Anfrage bekommt kein Konto.
func TestHandleLoginForwardAuthRejectsUnknownSubjectWithoutInvite(t *testing.T) {
	a := newForwardAuthTestAPI(t)
	req := forwardAuthRequest(LoginForwardAuthPath, "unknown-subject", "shared-secret-value")

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusUnauthorized)
	}
}

// Verifikationspunkt 4: unbekannter Nutzer MIT gültigem
// Einladungs-Kennzeichen bekommt eine Sitzung, über genau diesen Pfad.
func TestHandleLoginForwardAuthCreatesSessionFromInvite(t *testing.T) {
	a := newForwardAuthTestAPI(t)
	token, err := invite.Create(a.store.DB(), 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}

	req := forwardAuthRequest(LoginForwardAuthPath+"?invite="+token, "new-subject", "shared-secret-value")

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusFound {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusFound)
	}
	if findSetCookie(rec.Result(), a.cfg.SessionCookieName) == nil {
		t.Error("no session cookie was set")
	}

	var redeemedBy sql.NullString
	if err := a.store.DB().QueryRow(`SELECT redeemed_by FROM invitations`).Scan(&redeemedBy); err != nil {
		t.Fatalf("read invitation: %v", err)
	}
	if !redeemedBy.Valid {
		t.Error("invitation was not redeemed")
	}
}

func TestHandleLoginForwardAuthAnswers404WhenDisabled(t *testing.T) {
	a := newTestAPI(t) // forward-auth NOT enabled
	req := httptest.NewRequest(http.MethodGet, LoginForwardAuthPath, nil)

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusNotFound {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusNotFound)
	}
}
