package api

import (
	"encoding/json"
	"net/http"
)

// handleTokenStatus answers "does this token authenticate, and as whom". It
// mirrors the status endpoint Sonarr, Radarr and Prowlarr each expose for
// checking an API key, and it gives the token-space its first real route so
// the inventory gate and the running-server tests check the prefix/middleware
// pairing against something more than the health path's exception.
func (a *API) handleTokenStatus(w http.ResponseWriter, r *http.Request) {
	userID, _ := userIDFromContext(r.Context())
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	_ = json.NewEncoder(w).Encode(map[string]string{"user_id": userID})
}
