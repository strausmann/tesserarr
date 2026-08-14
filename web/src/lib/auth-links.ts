// The URL that starts the OIDC login flow (OIDCLogin.BeginLogin,
// internal/identity/oidc.go, wired to HTTP in internal/api/login.go). A
// visitor who has no session yet cannot pass requireSession, and
// BeginLogin's whole job is to redirect the browser to the provider before
// any session exists — so it has to be a plain, ordinary navigation the
// browser follows itself (a link, not a fetch call), reachable
// pre-session.
//
// It DOES sit under /api/v1/app — that was this file's original
// assumption to the contrary (a route "outside /api/v1, matching
// /invite/"), and it was wrong. internal/api/login.go's LoginOIDCPath
// registers it there with MiddlewarePublic, not MiddlewareSession: a route
// can be public — reachable with no credential at all — while still living
// under the session prefix; nothing about being under PrefixApp implies a
// session check by itself (see internal/api/inventory.go's publicPaths,
// which is exactly the allow-list that makes this distinction explicit and
// enforced, checked against every registered route by CheckInventory).
export const LOGIN_START_URL = "/api/v1/app/login/oidc";

// Where the provider redirects the browser back to after login
// (identity.OIDCConfig.RedirectURL, built in internal/api.New from
// TESSERARR_BASE_URL plus this exact path — see LoginOIDCCallbackPath in
// internal/api/login.go, the single source of truth this constant has to
// keep matching). Not currently used by this app's own code — the provider
// calls it, not the frontend — but it is named here too so the pairing
// with LOGIN_START_URL stays documented in one place instead of only in a
// commit message.
export const LOGIN_CALLBACK_URL = "/api/v1/app/login/oidc/callback";
