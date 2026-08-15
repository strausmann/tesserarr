package api

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestHandleGetAccountReturnsDisplayNameEmailAndRole(t *testing.T) {
	a := newTestAPI(t)
	cookie := loginAsTestUser(t, a, "user-1")
	if _, err := a.store.DB().Exec(
		`UPDATE users SET display_name = ?, email = ?, role = ? WHERE id = ?`,
		"Friend", "friend@example.org", "familie", "user-1",
	); err != nil {
		t.Fatalf("seed user profile: %v", err)
	}

	req := httptest.NewRequest(http.MethodGet, AccountPath, nil)
	req.AddCookie(cookie)

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want %d, body: %s", rec.Code, http.StatusOK, rec.Body.String())
	}

	var resp accountResponse
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatalf("unmarshal response: %v", err)
	}
	if resp.DisplayName != "Friend" || resp.Email != "friend@example.org" || resp.Role != "familie" {
		t.Errorf("response = %+v, want {Friend friend@example.org familie}", resp)
	}
}

func TestHandleGetAccountReturnsEmptyEmailAndRoleWhenNeitherIsSet(t *testing.T) {
	a := newTestAPI(t)
	cookie := loginAsTestUser(t, a, "user-1")

	req := httptest.NewRequest(http.MethodGet, AccountPath, nil)
	req.AddCookie(cookie)

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	var resp accountResponse
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatalf("unmarshal response: %v", err)
	}
	if resp.Email != "" || resp.Role != "" {
		t.Errorf("response = %+v, want empty Email and Role", resp)
	}
}

func TestHandleUpdateAccountSetsEmail(t *testing.T) {
	a := newTestAPI(t)
	cookie := loginAsTestUser(t, a, "user-1")

	body, _ := json.Marshal(map[string]string{"email": "me@example.org"})
	req := httptest.NewRequest(http.MethodPatch, AccountPath, bytes.NewReader(body))
	req.AddCookie(cookie)
	ensureCSRFCookieForTest(t, a, req, cookie) // attaches BOTH halves; see its doc comment

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusNoContent {
		t.Fatalf("status = %d, want %d, body: %s", rec.Code, http.StatusNoContent, rec.Body.String())
	}

	var email string
	if err := a.store.DB().QueryRow(`SELECT email FROM users WHERE id = ?`, "user-1").Scan(&email); err != nil {
		t.Fatalf("read stored email: %v", err)
	}
	if email != "me@example.org" {
		t.Errorf("stored email = %q, want me@example.org", email)
	}
}

// TestHandleUpdateAccountRejectsRequestWithoutCSRFHeader attaches the CSRF
// COOKIE and then removes only the header, so it exercises requireCSRF's
// header comparison — the branch its name promises.
//
// Sending neither half would also yield 403, but from requireCSRF's earlier
// missing-cookie branch: the test would then stay green even if the header
// comparison were deleted outright, and would be measuring something other
// than what it claims. Prove the difference with the counter-test in step 4:
// delete the header comparison in csrf.go, and THIS test must go red.
// TestHandleUpdateAccountRejectsOversizedBodyWithoutReadingItFully proves
// handleUpdateAccount rejects an over-limit request body — and, more
// specifically, that it does so WITHOUT first buffering the whole thing into
// memory. json.Decoder must consume an entire JSON string token before it
// can hand it back, so an unguarded decode of {"email":"<huge>"} reads every
// byte of the oversized value no matter how far past maxAccountEmailLen (254)
// it is checked afterwards — the length check on req.Email in
// handleUpdateAccount runs only once the whole body already sits in memory.
// A caller who never lets go of the connection can drive that allocation
// arbitrarily high (measured: a 512 MiB body inflated the server process by
// roughly 2 GiB, a body a container memory limit would not survive).
//
// oversizedBodyLen is deliberately NOT expressed in terms of a production
// constant: this test has to compile and run — and fail red — against the
// pre-fix handler just as well as the fixed one, so it hardcodes its own
// notion of "way past anything a legitimate email could need".
func TestHandleUpdateAccountRejectsOversizedBodyWithoutReadingItFully(t *testing.T) {
	a := newTestAPI(t)
	cookie := loginAsTestUser(t, a, "user-1")

	const oversizedBodyLen = 4096 * 4 // comfortably past any sane body-size guard
	payload := []byte(`{"email":"` + strings.Repeat("a", oversizedBodyLen) + `"}`)
	body := &countingReader{r: bytes.NewReader(payload)}

	req := httptest.NewRequest(http.MethodPatch, AccountPath, body)
	req.AddCookie(cookie)
	ensureCSRFCookieForTest(t, a, req, cookie)

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusBadRequest {
		t.Errorf("status = %d, want %d, body: %s", rec.Code, http.StatusBadRequest, rec.Body.String())
	}
	if body.read >= int64(len(payload)) {
		t.Errorf("handler read all %d bytes of the oversized body before rejecting it (read %d) — a size guard must reject the request before the decoder consumes the whole payload", len(payload), body.read)
	}
}

func TestHandleUpdateAccountRejectsRequestWithoutCSRFHeader(t *testing.T) {
	a := newTestAPI(t)
	cookie := loginAsTestUser(t, a, "user-1")

	body, _ := json.Marshal(map[string]string{"email": "me@example.org"})
	req := httptest.NewRequest(http.MethodPatch, AccountPath, bytes.NewReader(body))
	req.AddCookie(cookie)
	ensureCSRFCookieForTest(t, a, req, cookie)
	req.Header.Del(csrfHeaderName) // the case under test: cookie present, header missing

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusForbidden {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusForbidden)
	}
}
