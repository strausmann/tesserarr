package api

import (
	"database/sql"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"strings"
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

// TestHandleLoginForwardAuthRejectsEmptySubjectHeader guards the design
// doc's own assurance ("Ablauf", step 3: "Die Schlüssel-Kopfzeile wird
// gelesen. Fehlt sie oder ist sie leer -> Ablehnung.") — the "Schlüssel-
// Kopfzeile" there is the SUBJECT header (config.ForwardAuthHeaders.Subject,
// "the identity table's key"), not the shared-secret header
// ForwardAuthSecretHeaderName, which TestHandleLoginForwardAuthRejects-
// MissingSharedSecret already guards separately (verification point 1).
//
// Trust passes here (correct secret, trusted network) — the point under
// test is specifically that a PRESENT-BUT-EMPTY subject header is rejected
// the same way an entirely absent one would be, matching handleLoginForward-
// Auth's own "subject == \"\"" check, which reads no differently for either
// case (r.Header.Get returns "" for both a missing and an explicitly empty
// header). A guard sending an empty header — rather than omitting it — is a
// realistic misconfiguration this test exists to catch.
//
// This check turns out to be REDUNDANT with a second, independent guard:
// identity.ForwardAuthLogin.Authenticate rejects an empty Subject claim on
// its own (ErrForwardAuthSubjectEmpty, forwardauth.go — "a defense here
// costs nothing"). Disabling only the check below still yields 401,
// because Authenticate catches it next — the STATUS CODE alone cannot tell
// the two layers apart. The body text can: this handler's own check
// answers with the specific "forward-auth subject header missing", while
// falling through to Authenticate's generic, deliberately unspecific
// "login failed" (same principle as handleLoginOIDCCallback — a caller
// must not learn which guess came closer). Asserting the specific body
// text is what makes THIS check's own counter-proof meaningful rather than
// silently passing on the deeper layer's defense.
func TestHandleLoginForwardAuthRejectsEmptySubjectHeader(t *testing.T) {
	a := newForwardAuthTestAPI(t)
	req := forwardAuthRequest(LoginForwardAuthPath, "", "shared-secret-value")
	req.Header.Set("Remote-User-Id", "") // present, deliberately empty — not omitted

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusUnauthorized)
	}
	if !strings.Contains(rec.Body.String(), "subject header missing") {
		t.Errorf("body = %q, want it to name the missing subject header (this handler's own check, not identity.ForwardAuthLogin's deeper, deliberately generic rejection)", rec.Body.String())
	}
	if findSetCookie(rec.Result(), a.cfg.SessionCookieName) != nil {
		t.Error("a session cookie was set despite the empty subject header")
	}
}

// TestHandleLoginForwardAuthRejectsOverlongInviteToken guards the same
// unbounded-length defense maxInviteTokenLen already gives handleInvite-
// Redeem and handleLoginOIDCCallback's invite path (see maxInviteTokenLen's
// own comment in login.go) — but on THIS, newer entry path specifically.
// One of the security-audit run's four findings was exactly this shape: a
// defense that exists on the established paths but was never confirmed to
// also apply to a newly added one. A copy-pasted check is not the same as
// a verified one.
func TestHandleLoginForwardAuthRejectsOverlongInviteToken(t *testing.T) {
	a := newForwardAuthTestAPI(t)
	overlong := strings.Repeat("a", maxInviteTokenLen+1)
	req := forwardAuthRequest(LoginForwardAuthPath+"?invite="+overlong, "some-subject", "shared-secret-value")

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusBadRequest {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusBadRequest)
	}
	if findSetCookie(rec.Result(), a.cfg.SessionCookieName) != nil {
		t.Error("a session cookie was set despite the overlong invite token")
	}
}
