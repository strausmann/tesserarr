// Package api owns the HTTP surface.
//
// There are two path spaces, each with its own middleware, and they never share
// an authentication helper:
//
//   - PrefixApp ("/api/v1/app") is session-checked. Its middleware knows only
//     the session cookie, and every state-changing (non-safe-method) request
//     additionally has to pass a double-submit forgery check — see
//     requireCSRF in csrf.go.
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
//
// A third kind of path sits outside both spaces entirely and carries no check
// at all: MiddlewarePublic, for routes that are deliberately reachable with
// neither a session nor a token — a monitoring probe has no credential, an
// invited person redeeming an invite has no session yet, and the embedded
// browser interface (see static.go) is a static file that carries no
// sensitive data of its own — /api/v1 is its only path to anything worth
// protecting (ADR-0005), so gating delivery of the HTML/JS shell itself
// would protect nothing while blocking the very page (/login/) an
// unauthenticated visitor needs to reach. It is not a general escape hatch:
// CheckInventory (see inventory.go) only accepts it for a path named,
// together with its reason, on the explicit publicPaths allow-list. A
// route that carries MiddlewarePublic without being on that list is a
// finding, same as a route on the wrong prefix.
package api

import (
	"context"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/strausmann/tesserarr/internal/apitoken"
	"github.com/strausmann/tesserarr/internal/config"
	"github.com/strausmann/tesserarr/internal/identity"
	"github.com/strausmann/tesserarr/internal/store"
	"github.com/strausmann/tesserarr/internal/web"
)

// sessionTTL is the lifetime a newly created session is granted. 30 days is a
// deliberate middle ground for a self-hosted app: long enough that a user is
// not logged out mid-week, short enough that a leaked cookie eventually stops
// working on its own even if nobody notices and revokes it by hand.
const sessionTTL = 30 * 24 * time.Hour

// Path prefixes. Every registered route lives below exactly one of them,
// except the paths on the explicit public allow-list (see publicPaths in
// inventory.go), which carry MiddlewarePublic instead.
const (
	PrefixApp   = "/api/v1/app"
	PrefixToken = "/api/v1"
)

// Middleware identifies which check guards a route. It exists so the inventory
// test can assert the pairing of prefix and check without reflection tricks.
type Middleware int

const (
	// MiddlewareSession checks the session cookie and nothing else.
	MiddlewareSession Middleware = iota
	// MiddlewareToken checks the Authorization header and nothing else.
	MiddlewareToken
	// MiddlewarePublic guards nothing — it is reserved for routes that are
	// deliberately public, such as the health path or an invite-redeem
	// route. CheckInventory only accepts it for a path named, with its
	// reason, on the publicPaths allow-list (inventory.go) — carrying
	// MiddlewarePublic does not by itself excuse a route from that list.
	MiddlewarePublic
)

func (m Middleware) String() string {
	switch m {
	case MiddlewareSession:
		return "session"
	case MiddlewareToken:
		return "token"
	case MiddlewarePublic:
		return "public"
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
	cfg              *config.Config
	store            *store.Store
	sessions         *identity.SessionStore
	apiTokens        *apitoken.Store
	login            *identity.OIDCLogin
	forwardAuthLogin *identity.ForwardAuthLogin
	routes           []Route
	mux              *http.ServeMux
}

// New builds the API and registers every route.
//
// It discovers the configured OIDC provider (identity.NewOIDCLogin) as part
// of building the API — one network round trip, exactly like the store's own
// migration step already run by the time st reaches here. That belongs at
// startup, not per request, and a caller that cannot reach its provider
// should fail loudly there rather than serve a login button that can never
// work — see runServe in cmd/tesserarr/main.go, which returns New's error
// instead of starting the server.
func New(ctx context.Context, cfg *config.Config, st *store.Store) (*API, error) {
	sessions := identity.NewSessionStore(st.DB(), identity.SessionConfig{TTL: sessionTTL})

	// The redirect URI registered with the provider is always BaseURL plus
	// LoginOIDCCallbackPath — there is deliberately no separate
	// configuration setting for it (see config.Config's comment on
	// OIDCIssuerURL), the same "derive it, don't ask for it twice" choice
	// createInvite already makes for the invite redeem link.
	login, err := identity.NewOIDCLogin(ctx, st.DB(), sessions, identity.OIDCConfig{
		IssuerURL:    cfg.OIDCIssuerURL,
		ClientID:     cfg.OIDCClientID,
		ClientSecret: cfg.OIDCClientSecret,
		RedirectURL:  strings.TrimRight(cfg.BaseURL, "/") + LoginOIDCCallbackPath,
		DefaultRole:  cfg.DefaultRole,
	})
	if err != nil {
		return nil, fmt.Errorf("discover oidc provider: %w", err)
	}

	// No network round trip, unlike NewOIDCLogin above — a forward-auth
	// guard's headers are trusted synchronously per request (see
	// trustedForwardAuthSource in forward_auth_trust.go), so there is
	// nothing here to discover at startup. Shares sessions with login above
	// for the same reason ForwardAuthLogin's own comment gives: a
	// forward-auth session and an OIDC session must be indistinguishable to
	// requireSession (middleware.go).
	forwardAuthLogin := identity.NewForwardAuthLogin(st.DB(), sessions, cfg.RoleMap, cfg.DefaultRole)

	// web.FS's only failure mode (fs.Sub failing on the embedded "dist"
	// directory) is unreachable in practice — the directory is always
	// present at compile time — but it returns an error rather than
	// panicking, so New checks it rather than assuming success, same as
	// every other startup dependency here.
	webFS, err := web.FS()
	if err != nil {
		return nil, fmt.Errorf("embed web interface: %w", err)
	}

	a := &API{
		cfg:              cfg,
		store:            st,
		sessions:         sessions,
		apiTokens:        apitoken.New(st.DB()),
		login:            login,
		forwardAuthLogin: forwardAuthLogin,
		mux:              http.NewServeMux(),
	}
	a.register("/healthz", MiddlewarePublic, a.handleHealth)
	a.register(PrefixApp+"/session", MiddlewareSession, a.handleSessionStatus)
	a.register(PrefixToken+"/status", MiddlewareToken, a.handleTokenStatus)
	a.register(LoginOIDCPath, MiddlewarePublic, a.handleLoginOIDC)
	a.register(LoginOIDCCallbackPath, MiddlewarePublic, a.handleLoginOIDCCallback)
	a.register("GET /invite/{token}", MiddlewarePublic, a.handleInviteRedeem)
	a.register(LoginForwardAuthPath, MiddlewarePublic, a.handleLoginForwardAuth)
	// TokensPath carries all three verbs a signed-in user needs to manage
	// their own API tokens (see tokens.go): mint, list, revoke. All three
	// sit on MiddlewareSession like every other route in this block — only
	// an authenticated browser mints or revokes a credential that then lets
	// some OTHER client (Prowlarr, Sonarr, Radarr) skip that same check.
	a.register("POST "+TokensPath, MiddlewareSession, a.handleCreateToken)
	a.register("GET "+TokensPath, MiddlewareSession, a.handleListTokens)
	a.register("DELETE "+TokensPath+"/{hash}", MiddlewareSession, a.handleRevokeToken)
	// AccountPath is the signed-in user's own profile (see account.go): read
	// it in full, or set the one field the user is allowed to change
	// themselves (email). Both sit on MiddlewareSession like every other
	// route in this block — PATCH is an unsafe method, so requireCSRF
	// (csrf.go) guards it exactly like the token-minting POST above.
	a.register("GET "+AccountPath, MiddlewareSession, a.handleGetAccount)
	a.register("PATCH "+AccountPath, MiddlewareSession, a.handleUpdateAccount)
	a.register("POST "+LogoutPath, MiddlewareSession, a.handleLogout)
	// Registered last, but that has no bearing on precedence — see the
	// package comment above: ServeMux resolves every request to its most
	// specific matching pattern, never by registration order, so "/" only
	// ever catches what none of the routes above claimed.
	a.register("/", MiddlewarePublic, newStaticHandler(webFS))
	return a, nil
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
		// requireSession runs first: a request with no valid session cookie
		// is rejected with 401 before requireCSRF ever sees it. See the
		// package comment above and requireCSRF's own comment in csrf.go for
		// why the token case below never gets this second layer.
		return a.requireSession(a.requireCSRF(handler))
	case MiddlewareToken:
		return a.requireToken(handler)
	default:
		// MiddlewarePublic guards nothing, so it falls here — same as any
		// value outside the three known kinds, which String() likewise
		// treats as "unknown" rather than an error. Whether a route is
		// ALLOWED to carry MiddlewarePublic is CheckInventory's job
		// (inventory.go), not this function's.
		return handler
	}
}

// requireSession and requireToken are defined in middleware.go, requireCSRF
// in csrf.go — this file only ever wires them into wrap() and register(),
// never their bodies.

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
