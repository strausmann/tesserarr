// The URL that starts the OIDC login flow (OIDCLogin.BeginLogin,
// internal/identity/oidc.go). It is deliberately NOT under /api/v1 — a
// visitor who has no session yet cannot pass requireSession, and
// BeginLogin's whole job is to redirect the browser to the provider before
// any session exists — so it has to be a plain, ordinary navigation the
// browser follows itself (a link, not a fetch call), served outside both
// PrefixApp and PrefixToken. The existing /invite/ route
// (internal/api/inventory.go's publicPaths) is the one precedent for a
// route in that position: reachable pre-session, with the credential (here:
// nothing yet, there: the invite token) living in the request itself.
//
// ASSUMPTION, not yet wired: as of this writing internal/api/router.go does
// not register a login route at all — binding OIDCLogin's BeginLogin/
// Callback to HTTP is a separate, parallel task. /auth/login and
// /auth/callback here are this file's best guess at where that wiring will
// land, chosen to sit clearly outside /api/v1 (matching /invite/) and
// outside every path this app's own pages occupy (/, /login/, /logout/) so
// neither side can shadow the other on the static file server. If the
// actual routes differ, this file — and RedirectURL in whatever configures
// OIDCConfig — are the only places that need to change.
export const LOGIN_START_URL = "/auth/login";

// Where the provider redirects the browser back to after login
// (OIDCConfig.RedirectURL). Not currently used by this app directly — the
// provider calls it, not the frontend's own code — but it is named here
// too so the pairing with LOGIN_START_URL is documented in one place
// instead of only in a commit message.
export const LOGIN_CALLBACK_URL = "/auth/callback";
