package api

import (
	"database/sql"
	"encoding/json"
	"net/http"
)

// AccountPath is the signed-in user's own profile: display name, email and
// current role — entirely read-only. Email arrives exclusively from a
// trusted source (an OIDC provider's own claims, or a forward-auth guard's
// header — see internal/identity/forwardauth.go's updateProfile) and is
// never user-editable through this route or any other; see this route's
// doc comment history (and docs/forward-auth.md) for why a self-entered
// value was removed rather than kept alongside the trusted one.
const AccountPath = PrefixApp + "/account"

// accountResponse is what handleGetAccount returns. Email and Role render
// as empty strings, never null, when the user has neither set — that keeps
// the frontend's JSON decoding uniform (a plain string field, not a
// pointer) regardless of whether a forward-auth guard or the user
// themselves has ever supplied a value.
type accountResponse struct {
	DisplayName string `json:"display_name"`
	Email       string `json:"email"`
	Role        string `json:"role"`
}

// handleGetAccount serves the signed-in user's own profile. It never takes
// a path parameter for the user id — userIDFromContext (set by
// requireSession, see middleware.go) is the only source of "which user", so
// there is no way to ask for anyone else's profile through this route.
func (a *API) handleGetAccount(w http.ResponseWriter, r *http.Request) {
	userID, _ := userIDFromContext(r.Context())

	// email is nullable in the schema (migration 0004_forward_auth_profile.sql)
	// — a brand-new user who signed in via forward-auth without ever
	// entering an email, and whose guard never supplied one either, has no
	// row value at all yet. sql.NullString absorbs that without an error;
	// its zero value serializes as "" below, same as role's NOT NULL
	// DEFAULT ''.
	var displayName, role string
	var email sql.NullString
	err := a.store.DB().QueryRow(
		`SELECT display_name, email, role FROM users WHERE id = ?`, userID,
	).Scan(&displayName, &email, &role)
	if err != nil {
		http.Error(w, "load account failed", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	_ = json.NewEncoder(w).Encode(accountResponse{
		DisplayName: displayName,
		Email:       email.String,
		Role:        role,
	})
}
