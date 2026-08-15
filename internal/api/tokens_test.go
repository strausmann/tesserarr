package api

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestHandleCreateTokenReturnsRawTokenOnceAndPersistsMetadata(t *testing.T) {
	a := newTestAPI(t)
	cookie := loginAsTestUser(t, a, "user-1")

	body, _ := json.Marshal(map[string]any{"label": "sonarr", "ttl_seconds": 0})
	req := httptest.NewRequest(http.MethodPost, TokensPath, bytes.NewReader(body))
	req.AddCookie(cookie)
	// POST is just as much an unsafe method under requireCSRF as DELETE —
	// minting a token is exactly the kind of state change ADR-0007 and
	// csrf.go's own comment describe the double-submit check as guarding.
	// Skipping this and relying on the session cookie alone would make the
	// forgery check silently bypassable for the one endpoint that hands out
	// a brand-new, long-lived credential.
	ensureCSRFCookieForTest(t, a, req, cookie)
	req.Header.Set("Content-Type", "application/json")

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusCreated {
		t.Fatalf("status = %d, want %d, body: %s", rec.Code, http.StatusCreated, rec.Body.String())
	}

	var resp createTokenResponse
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatalf("unmarshal response: %v", err)
	}
	if resp.Token == "" {
		t.Error("response carries no raw token")
	}
	if resp.Label != "sonarr" {
		t.Errorf("Label = %q, want sonarr", resp.Label)
	}

	if _, err := a.apiTokens.Lookup(resp.Token); err != nil {
		t.Errorf("the returned token does not authenticate: %v", err)
	}
}

// TestHandleCreateTokenRequiresCSRF proves the state-changing-forgery
// safeguard actually applies to THIS route, through the real production
// wiring (a.Handler(), the exact chain router.go builds for POST
// TokensPath) rather than only through requireCSRF in isolation — a valid
// session cookie alone must not be enough to mint a token, or a page on a
// different origin could silently hand a visitor's session a brand-new,
// long-lived credential the visitor never asked for.
func TestHandleCreateTokenRequiresCSRF(t *testing.T) {
	a := newTestAPI(t)
	cookie := loginAsTestUser(t, a, "user-1")

	body, _ := json.Marshal(map[string]any{"label": "sonarr", "ttl_seconds": 0})
	req := httptest.NewRequest(http.MethodPost, TokensPath, bytes.NewReader(body))
	req.AddCookie(cookie) // no CSRF cookie/header attached — the case under test

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusForbidden {
		t.Errorf("status = %d, want %d (a valid session alone must not be enough to mint a token)", rec.Code, http.StatusForbidden)
	}
}

func TestHandleCreateTokenRejectsEmptyLabel(t *testing.T) {
	a := newTestAPI(t)
	cookie := loginAsTestUser(t, a, "user-1")

	body, _ := json.Marshal(map[string]any{"label": "  "})
	req := httptest.NewRequest(http.MethodPost, TokensPath, bytes.NewReader(body))
	req.AddCookie(cookie)
	ensureCSRFCookieForTest(t, a, req, cookie) // see the comment in the test above

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusBadRequest {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusBadRequest)
	}
}

// TestHandleCreateTokenRejectsOversizedBodyWithoutReadingItFully is the
// tokens.go counterpart of account_test.go's
// TestHandleUpdateAccountRejectsOversizedBodyWithoutReadingItFully — same
// reasoning: json.Decoder must consume an entire JSON string token before it
// can hand it back, so {"label":"<huge>"} reads every byte of an oversized
// value no matter how far past maxTokenLabelLen (100) it is checked
// afterwards. See that test's own comment for why oversizedBodyLen is not
// expressed in terms of a production constant.
func TestHandleCreateTokenRejectsOversizedBodyWithoutReadingItFully(t *testing.T) {
	a := newTestAPI(t)
	cookie := loginAsTestUser(t, a, "user-1")

	const oversizedBodyLen = 4096 * 4 // comfortably past any sane body-size guard
	payload := []byte(`{"label":"` + strings.Repeat("a", oversizedBodyLen) + `"}`)
	body := &countingReader{r: bytes.NewReader(payload)}

	req := httptest.NewRequest(http.MethodPost, TokensPath, body)
	req.AddCookie(cookie)
	ensureCSRFCookieForTest(t, a, req, cookie)
	req.Header.Set("Content-Type", "application/json")

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusBadRequest {
		t.Errorf("status = %d, want %d, body: %s", rec.Code, http.StatusBadRequest, rec.Body.String())
	}
	if body.read >= int64(len(payload)) {
		t.Errorf("handler read all %d bytes of the oversized body before rejecting it (read %d) — a size guard must reject the request before the decoder consumes the whole payload", len(payload), body.read)
	}
}

func TestHandleListTokensDoesNotIncludeRawToken(t *testing.T) {
	a := newTestAPI(t)
	cookie := loginAsTestUser(t, a, "user-1")
	if _, _, err := a.apiTokens.Create("user-1", "sonarr", 0); err != nil {
		t.Fatalf("apiTokens.Create() returned error: %v", err)
	}

	req := httptest.NewRequest(http.MethodGet, TokensPath, nil)
	req.AddCookie(cookie)

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusOK)
	}
	if bytes.Contains(rec.Body.Bytes(), []byte(`"token"`)) {
		t.Errorf("list response carries a raw-token field: %s", rec.Body.String())
	}

	var resp []tokenResponse
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatalf("unmarshal response: %v", err)
	}
	if len(resp) != 1 || resp[0].Label != "sonarr" {
		t.Errorf("response = %+v, want exactly one token labeled sonarr", resp)
	}
}

func TestHandleListTokensOnlyReturnsCallersOwnTokens(t *testing.T) {
	a := newTestAPI(t)
	cookie := loginAsTestUser(t, a, "user-1")
	loginAsTestUser(t, a, "user-2")
	if _, _, err := a.apiTokens.Create("user-2", "someone-elses-token", 0); err != nil {
		t.Fatalf("apiTokens.Create() returned error: %v", err)
	}

	req := httptest.NewRequest(http.MethodGet, TokensPath, nil)
	req.AddCookie(cookie)

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	var resp []tokenResponse
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatalf("unmarshal response: %v", err)
	}
	if len(resp) != 0 {
		t.Errorf("response = %+v, want empty (user-1 has no tokens of their own)", resp)
	}
}

func TestHandleRevokeTokenInvalidatesIt(t *testing.T) {
	a := newTestAPI(t)
	cookie := loginAsTestUser(t, a, "user-1")
	rawToken, meta, err := a.apiTokens.Create("user-1", "sonarr", 0)
	if err != nil {
		t.Fatalf("apiTokens.Create() returned error: %v", err)
	}

	req := httptest.NewRequest(http.MethodDelete, TokensPath+"/"+meta.Hash, nil)
	req.AddCookie(cookie)
	ensureCSRFCookieForTest(t, a, req, cookie)

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusNoContent {
		t.Fatalf("status = %d, want %d, body: %s", rec.Code, http.StatusNoContent, rec.Body.String())
	}
	if _, err := a.apiTokens.Lookup(rawToken); err == nil {
		t.Error("the revoked token still authenticates")
	}
}

func TestHandleRevokeTokenCannotDeleteAnotherUsersToken(t *testing.T) {
	a := newTestAPI(t)
	cookie := loginAsTestUser(t, a, "user-1")
	loginAsTestUser(t, a, "user-2")
	rawToken, meta, err := a.apiTokens.Create("user-2", "not-yours", 0)
	if err != nil {
		t.Fatalf("apiTokens.Create() returned error: %v", err)
	}

	req := httptest.NewRequest(http.MethodDelete, TokensPath+"/"+meta.Hash, nil)
	req.AddCookie(cookie)
	ensureCSRFCookieForTest(t, a, req, cookie)

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusNoContent {
		t.Fatalf("status = %d, want %d (idempotent no-op)", rec.Code, http.StatusNoContent)
	}
	if _, err := a.apiTokens.Lookup(rawToken); err != nil {
		t.Errorf("user-2's token was deleted by user-1's request: %v", err)
	}
}

// TestHandleRevokeTokenRequiresCSRF is the DELETE-side counterpart of
// TestHandleCreateTokenRequiresCSRF — same reasoning, same missing
// safeguard, this time guarding against a forged request silently revoking
// a token the visitor still relies on (Prowlarr/Sonarr/Radarr all stop
// working the moment their configured token is gone).
func TestHandleRevokeTokenRequiresCSRF(t *testing.T) {
	a := newTestAPI(t)
	cookie := loginAsTestUser(t, a, "user-1")
	_, meta, err := a.apiTokens.Create("user-1", "sonarr", 0)
	if err != nil {
		t.Fatalf("apiTokens.Create() returned error: %v", err)
	}

	req := httptest.NewRequest(http.MethodDelete, TokensPath+"/"+meta.Hash, nil)
	req.AddCookie(cookie) // no CSRF cookie/header attached — the case under test

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusForbidden {
		t.Errorf("status = %d, want %d (a valid session alone must not be enough to revoke a token)", rec.Code, http.StatusForbidden)
	}
}

// ensureCSRFCookieForTest performs one safe GET to acquire a CSRF cookie for
// sessionCookie's session, then attaches BOTH halves of the double-submit
// pair required for an unsafe-method request to pass requireCSRF (csrf.go)
// onto req: the cookie itself (req.AddCookie) and the matching
// X-CSRF-Token header (req.Header.Set) — deliberately mutating req directly
// rather than only returning the value, because a caller that set the
// header from this function's return value while forgetting to also attach
// the cookie would still get a 403 (requireCSRF checks the cookie first,
// see its own comment) with no compiler or vet warning to catch the
// omission. csrf_test.go's own tests build this cookie inline per case
// instead of through a shared helper (see e.g.
// TestSessionPathAcceptsUnsafeMethodWithMatchingCSRF), so there is nothing
// there yet to reuse; this is the first helper of its kind, and Aufgabe 8's
// account_test.go is expected to reuse THIS one rather than add a third
// copy of the same warm-up GET.
func ensureCSRFCookieForTest(t *testing.T, a *API, req *http.Request, sessionCookie *http.Cookie) {
	t.Helper()

	warmup := httptest.NewRequest(http.MethodGet, PrefixApp+"/session", nil)
	warmup.AddCookie(sessionCookie)
	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, warmup)

	cookie := findSetCookie(rec.Result(), csrfCookieName)
	if cookie == nil {
		t.Fatal("warm-up request carries no csrf cookie")
	}
	req.AddCookie(cookie)
	req.Header.Set(csrfHeaderName, cookie.Value)
}
