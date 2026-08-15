package api

import (
	"encoding/json"
	"net/http"
	"strings"
	"time"
)

// TokensPath is where a signed-in user manages their own API tokens — the
// credentials ADR-0007 lets Prowlarr, Sonarr and Radarr use instead of a
// browser session. It sits on the session path space (PrefixApp): minting
// or revoking one of these tokens is something only an authenticated
// BROWSER does, never the token-holding client itself.
const TokensPath = PrefixApp + "/tokens"

// maxTokenLabelLen bounds the label a caller can attach to a token — purely
// a display string (see apitoken.Token), with no reason to accept
// unbounded input from an authenticated session.
const maxTokenLabelLen = 100

// createTokenRequest is the body handleCreateToken decodes. TTLSeconds
// mirrors apitoken.Store.Create's own ttl parameter exactly: <= 0 means
// "never expires", the default a caller gets by omitting the field
// entirely (Go's zero value for int64 is 0).
type createTokenRequest struct {
	Label      string `json:"label"`
	TTLSeconds int64  `json:"ttl_seconds"`
}

// createTokenResponse is the ONLY place a raw token value is ever returned
// to a caller — see apitoken.Store.Create's own comment. Every other
// response type in this file (tokenResponse) deliberately has no Token
// field at all, not merely an omitted one.
type createTokenResponse struct {
	Token     string  `json:"token"`
	Hash      string  `json:"hash"`
	Label     string  `json:"label"`
	CreatedAt string  `json:"created_at"`
	ExpiresAt *string `json:"expires_at"`
}

// formatOptionalTime is shared by every response type in this package that
// carries an optional timestamp (Token.LastUsedAt, Token.ExpiresAt, and
// account.go's own use for the same shape) — one place that decides "nil
// in, null out" so the JSON these handlers emit never has to distinguish
// "never happened" from "happened at the zero time".
func formatOptionalTime(t *time.Time) *string {
	if t == nil {
		return nil
	}
	s := t.Format(time.RFC3339)
	return &s
}

// handleCreateToken mints a new API token for the caller. The plaintext
// token appears in THIS response and nowhere else ever again — see
// apitoken.Store.Create's own comment. It is registered on PrefixApp, so
// wrap() has already run it through requireSession AND requireCSRF
// (router.go) by the time it gets here: only an authenticated browser that
// also proved it is not a forged cross-site request can mint a token that
// lets some OTHER client skip both of those checks from then on.
func (a *API) handleCreateToken(w http.ResponseWriter, r *http.Request) {
	userID, _ := userIDFromContext(r.Context())

	// See maxRequestBodyBytes's own comment (middleware.go): this MUST run
	// before Decode, not after — decoding a JSON string requires reading the
	// whole value first, so a length check on req.Label afterwards would
	// already be too late to bound how much of an oversized body ends up in
	// memory.
	r.Body = http.MaxBytesReader(w, r.Body, maxRequestBodyBytes)

	var req createTokenRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		http.Error(w, "invalid request body", http.StatusBadRequest)
		return
	}
	req.Label = strings.TrimSpace(req.Label)
	if req.Label == "" || len(req.Label) > maxTokenLabelLen {
		http.Error(w, "label is required and must be at most 100 characters", http.StatusBadRequest)
		return
	}
	if req.TTLSeconds < 0 {
		http.Error(w, "ttl_seconds must not be negative", http.StatusBadRequest)
		return
	}

	token, meta, err := a.apiTokens.Create(userID, req.Label, time.Duration(req.TTLSeconds)*time.Second)
	if err != nil {
		http.Error(w, "create token failed", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusCreated)
	_ = json.NewEncoder(w).Encode(createTokenResponse{
		Token:     token,
		Hash:      meta.Hash,
		Label:     meta.Label,
		CreatedAt: meta.CreatedAt.Format(time.RFC3339),
		ExpiresAt: formatOptionalTime(meta.ExpiresAt),
	})
}

// tokenResponse is what handleListTokens emits per token — deliberately the
// same shape apitoken.Token carries minus the raw value, because there is
// no raw value to carry: List (internal/apitoken/token.go) never has one to
// give back, Create's return value was the only time it ever existed.
type tokenResponse struct {
	Hash       string  `json:"hash"`
	Label      string  `json:"label"`
	CreatedAt  string  `json:"created_at"`
	LastUsedAt *string `json:"last_used_at"`
	ExpiresAt  *string `json:"expires_at"`
}

// handleListTokens lists the caller's own tokens — apiTokens.List already
// scopes by userID, so there is nothing extra to filter here. Encoding an
// empty slice (rather than a nil one) as "[]" rather than "null" is
// json.Marshal's own behavior for a non-nil, zero-length slice — out is
// built with make(..., 0, ...) precisely so a caller with no tokens gets an
// empty JSON array, not the literal null a nil slice would produce.
func (a *API) handleListTokens(w http.ResponseWriter, r *http.Request) {
	userID, _ := userIDFromContext(r.Context())

	tokens, err := a.apiTokens.List(userID)
	if err != nil {
		http.Error(w, "list tokens failed", http.StatusInternalServerError)
		return
	}

	out := make([]tokenResponse, 0, len(tokens))
	for _, t := range tokens {
		out = append(out, tokenResponse{
			Hash:       t.Hash,
			Label:      t.Label,
			CreatedAt:  t.CreatedAt.Format(time.RFC3339),
			LastUsedAt: formatOptionalTime(t.LastUsedAt),
			ExpiresAt:  formatOptionalTime(t.ExpiresAt),
		})
	}

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	_ = json.NewEncoder(w).Encode(out)
}

// handleRevokeToken revokes one of the caller's own tokens. Safe against a
// hash belonging to someone else by construction — apiTokens.Revoke scopes
// its DELETE to userID (see internal/apitoken/token.go, Aufgabe 6) — so
// there is no separate ownership check to get wrong here: a request for
// someone else's hash and a request for an unknown hash look identical all
// the way down, both a no-op, both 204.
func (a *API) handleRevokeToken(w http.ResponseWriter, r *http.Request) {
	userID, _ := userIDFromContext(r.Context())
	hash := r.PathValue("hash")
	if hash == "" {
		http.Error(w, "token hash required", http.StatusBadRequest)
		return
	}

	if err := a.apiTokens.Revoke(userID, hash); err != nil {
		http.Error(w, "revoke token failed", http.StatusInternalServerError)
		return
	}
	w.WriteHeader(http.StatusNoContent)
}
