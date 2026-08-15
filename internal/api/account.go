package api

import (
	"database/sql"
	"encoding/json"
	"net/http"
	"strings"
)

// AccountPath is the signed-in user's own profile: display name, email and
// current role — read-only except for email, which the user can set for
// themselves whenever no forward-auth guard has supplied one. A
// portal-supplied email always overwrites a self-entered one on the NEXT
// forward-auth entry (see internal/identity/forwardauth.go's
// updateProfile), never the reverse — this endpoint does not need to
// special-case that; it simply always accepts what the caller sends.
const AccountPath = PrefixApp + "/account"

// maxAccountEmailLen follows RFC 5321 §4.5.3.1.3's maximum for a full
// reverse-path.
const maxAccountEmailLen = 254

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

// updateAccountRequest is the body handleUpdateAccount decodes. Email is the
// only field — display name and role are not user-editable through this
// endpoint (display name comes from the identity provider, role is either
// operator- or guard-assigned, see migration 0004_forward_auth_profile.sql).
type updateAccountRequest struct {
	Email string `json:"email"`
}

// handleUpdateAccount lets the signed-in user set their own email. This is
// the ONLY mutation this route exposes — see AccountPath's comment for why
// that is safe even though a forward-auth guard can also write this same
// column: the guard always wins again on the user's next forward-auth entry,
// so a self-entered value only ever fills the gap when no guard is in the
// picture (or hasn't supplied one yet), never overrides one.
func (a *API) handleUpdateAccount(w http.ResponseWriter, r *http.Request) {
	userID, _ := userIDFromContext(r.Context())

	// See maxRequestBodyBytes's own comment (middleware.go): this MUST run
	// before Decode, not after — decoding a JSON string requires reading the
	// whole value first, so a length check on req.Email afterwards would
	// already be too late to bound how much of an oversized body ends up in
	// memory.
	r.Body = http.MaxBytesReader(w, r.Body, maxRequestBodyBytes)

	var req updateAccountRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		http.Error(w, "invalid request body", http.StatusBadRequest)
		return
	}
	req.Email = strings.TrimSpace(req.Email)
	if len(req.Email) > maxAccountEmailLen {
		http.Error(w, "email too long", http.StatusBadRequest)
		return
	}

	// An empty string is stored as NULL, not "" — that keeps "no email set"
	// a single representation (NULL) rather than two (NULL from the schema
	// default, "" from a user who submitted an empty field), which is what
	// lets handleGetAccount above rely on sql.NullString's zero value alone.
	var email any
	if req.Email != "" {
		email = req.Email
	}
	if _, err := a.store.DB().Exec(`UPDATE users SET email = ? WHERE id = ?`, email, userID); err != nil {
		http.Error(w, "update account failed", http.StatusInternalServerError)
		return
	}
	w.WriteHeader(http.StatusNoContent)
}
