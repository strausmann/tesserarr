package api

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
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

// TestHandleUpdateAccountNoLongerExists locks in the removal of the PATCH
// mutation this route used to expose: email is set exclusively by a
// trusted source now (an OIDC provider's claims or a forward-auth guard's
// header, see internal/identity/forwardauth.go's updateProfile), never by
// the signed-in user themselves — see AccountPath's own doc comment.
//
// The expected outcome is 404, not 405: "PATCH /api/v1/app/account" is no
// longer a registered pattern at all (see router.go — only "GET
// "+AccountPath is registered now), so net/http.ServeMux falls through to
// "/", the lowest-specificity catch-all newStaticHandler serves (see
// static.go and the package comment on router.go). That handler answers an
// unknown path with the embedded interface's own compiled 404 page at
// status 404 — never a 405, since ServeMux only ever produces 405 when a
// path has NO less-specific match to fall back to, which is not the case
// here.
func TestHandleUpdateAccountNoLongerExists(t *testing.T) {
	a := newTestAPI(t)
	cookie := loginAsTestUser(t, a, "user-1")

	body, _ := json.Marshal(map[string]string{"email": "me@example.org"})
	req := httptest.NewRequest(http.MethodPatch, AccountPath, bytes.NewReader(body))
	req.AddCookie(cookie)

	rec := httptest.NewRecorder()
	a.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusNotFound {
		t.Fatalf("status = %d, want %d, body: %s", rec.Code, http.StatusNotFound, rec.Body.String())
	}
}
