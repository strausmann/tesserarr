package api

import (
	"encoding/json"
	"net/http"
)

// handleSessionStatus answers "who does this session belong to". It is the
// simplest possible session-space route, and it exists mainly so the
// inventory gate (see inventory.go) and the selftest (see
// internal/selftest) have a real, session-checked route to check against
// instead of only the health path's deliberate exception — later Teil B
// tasks add the routes the actual browser-facing features need.
func (a *API) handleSessionStatus(w http.ResponseWriter, r *http.Request) {
	userID, _ := userIDFromContext(r.Context())
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	_ = json.NewEncoder(w).Encode(map[string]string{"user_id": userID})
}
