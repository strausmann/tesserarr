package api

import (
	"encoding/json"
	"net/http"
)

// handleHealth answers the liveness probe. It reports that the process runs and
// that the database answers — nothing more. It deliberately carries no
// authentication: a probe has no credential.
//
// A passing health check proves the process is alive. It proves nothing about
// whether the service is usable — the real endpoints are checked by selftest.
func (a *API) handleHealth(w http.ResponseWriter, r *http.Request) {
	if err := a.store.DB().PingContext(r.Context()); err != nil {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusServiceUnavailable)
		_ = json.NewEncoder(w).Encode(map[string]string{"status": "database unreachable"})
		return
	}
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	_ = json.NewEncoder(w).Encode(map[string]string{"status": "ok"})
}
