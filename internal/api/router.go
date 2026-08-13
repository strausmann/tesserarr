// Package api owns the HTTP surface.
//
// There are two path spaces, each with its own middleware, and they never share
// an authentication helper:
//
//   - PrefixApp ("/api/v1/app") is session-checked. Its middleware knows only
//     the session cookie.
//   - PrefixToken ("/api/v1") is token-checked. Its middleware knows only the
//     Authorization header — it does not read the session cookie, not even
//     defensively.
//
// The token path deliberately carries NO cross-site request forgery protection.
// That is not an oversight: forgery is structurally a cookie problem. A browser
// attaches cookies automatically; an Authorization header is never sent
// automatically and a foreign page cannot add one without a CORS preflight it
// does not get. Adding a forgery check here would break Prowlarr, Sonarr and
// Radarr, which send no cookies at all.
package api

import (
	"net/http"

	"github.com/strausmann/tesserarr/internal/config"
	"github.com/strausmann/tesserarr/internal/store"
)

// Path prefixes. Every registered route lives below exactly one of them — with
// the single, tested exception of the health path.
const (
	PrefixApp   = "/api/v1/app"
	PrefixToken = "/api/v1"
)

// Middleware identifies which check guards a route. It exists so the inventory
// test can assert the pairing of prefix and check without reflection tricks.
type Middleware int

const (
	// MiddlewareNone guards nothing. Only the health path may use it.
	MiddlewareNone Middleware = iota
	// MiddlewareSession checks the session cookie and nothing else.
	MiddlewareSession
	// MiddlewareToken checks the Authorization header and nothing else.
	MiddlewareToken
)

func (m Middleware) String() string {
	switch m {
	case MiddlewareNone:
		return "none"
	case MiddlewareSession:
		return "session"
	case MiddlewareToken:
		return "token"
	default:
		return "unknown"
	}
}

// Route is one registered path together with the check in front of it.
type Route struct {
	Pattern    string
	Middleware Middleware
}

// API holds the dependencies of the HTTP surface.
type API struct {
	cfg    *config.Config
	store  *store.Store
	routes []Route
	mux    *http.ServeMux
}

// New builds the API and registers every route.
func New(cfg *config.Config, st *store.Store) *API {
	a := &API{cfg: cfg, store: st, mux: http.NewServeMux()}
	a.register("/healthz", MiddlewareNone, a.handleHealth)
	return a
}

// register records the route in the inventory and installs it on the mux with
// the middleware that belongs to it. Routes are never added to the mux
// directly — the inventory would then miss them, and the inventory is the gate.
func (a *API) register(pattern string, mw Middleware, handler http.HandlerFunc) {
	a.routes = append(a.routes, Route{Pattern: pattern, Middleware: mw})
	a.mux.Handle(pattern, a.wrap(mw, handler))
}

func (a *API) wrap(mw Middleware, handler http.HandlerFunc) http.Handler {
	switch mw {
	case MiddlewareSession:
		return a.requireSession(handler)
	case MiddlewareToken:
		return a.requireToken(handler)
	default:
		return handler
	}
}

// requireSession is the session-only check. Teil B fills it; until then it
// rejects everything, which is the safe direction for an unfinished check.
func (a *API) requireSession(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "authentication not implemented", http.StatusUnauthorized)
	})
}

// requireToken is the token-only check. It must never look at the session
// cookie — see the package comment.
func (a *API) requireToken(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "authentication not implemented", http.StatusUnauthorized)
	})
}

// Routes returns the registered routes. The inventory test reads this from the
// running server rather than from a hand-kept list, because a hand-kept list
// drifts from reality — which is the very failure this gate exists to prevent.
func (a *API) Routes() []Route {
	out := make([]Route, len(a.routes))
	copy(out, a.routes)
	return out
}

// Handler returns the mux.
func (a *API) Handler() http.Handler { return a.mux }
