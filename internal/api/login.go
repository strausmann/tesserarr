package api

import (
	"net/http"

	"github.com/strausmann/tesserarr/internal/identity"
)

// LoginOIDCPath starts an ordinary login against the configured OIDC
// provider — someone who already has an identity on file, proving it
// again. It sits under PrefixApp even though it carries no session check:
// the browser-facing login surface belongs together with the rest of the
// app path space, and MiddlewarePublic (not a different prefix) is what
// marks it as the deliberate, allow-listed exception — see publicPaths in
// inventory.go, which names the reason: there is no session yet to check.
const LoginOIDCPath = PrefixApp + "/login/oidc"

// LoginOIDCCallbackPath is where the configured OIDC provider redirects the
// browser back to after a login, whether that login started at
// LoginOIDCPath or at an invite redeem link (see handleInviteRedeem). It
// carries no session cookie of its own to check — that is the entire
// reason a login exists — so it can never sit behind requireSession.
//
// This constant's value doubles as the path New uses to build the redirect
// URI it registers with the provider (cfg.BaseURL + this constant, the
// same "derive it from BaseURL instead of asking for it separately" choice
// createInvite already makes for the invite redeem link) — changing it
// changes what must be registered with the provider, too.
const LoginOIDCCallbackPath = LoginOIDCPath + "/callback"

// LogoutPath destroys the caller's session. It is a state change (a POST),
// so it sits on the session path space like any other route there —
// requireSession AND requireCSRF both run in front of it (see wrap in
// router.go).
const LogoutPath = PrefixApp + "/logout"

// handleLoginOIDC starts an ordinary login: no invitation, an existing
// identity proving itself again (identity.OIDCLogin.BeginLogin's ""
// case). See handleInviteRedeem for the invited person's entry point.
func (a *API) handleLoginOIDC(w http.ResponseWriter, r *http.Request) {
	redirectToProvider(w, r, a.login, "")
}

// handleInviteRedeem is the invited person's entry point: the token in the
// path (r.PathValue("token")) is their only credential — they have no
// session and no identity on file yet (see the "/invite/" entry on
// publicPaths, inventory.go). It never redeems the invitation itself —
// ADR-0004 requires that check to happen only once the provider has
// confirmed who this is, which is Callback's job, not this handler's. It
// only carries the token forward into the same login start
// handleLoginOIDC uses, so Callback has it when that moment comes.
//
// An empty token is rejected here rather than carried forward as an empty
// inviteToken, which identity.OIDCLogin.BeginLogin/Callback would
// otherwise treat as "no invitation at all" — that would turn a malformed
// invite link into a confusing "you need an invite" rejection at the
// provider, instead of an immediate, honest "that is not a valid link".
func (a *API) handleInviteRedeem(w http.ResponseWriter, r *http.Request) {
	token := r.PathValue("token")
	if token == "" {
		http.Error(w, "invite token required", http.StatusBadRequest)
		return
	}
	redirectToProvider(w, r, a.login, token)
}

// redirectToProvider starts a new login attempt and sends the browser to
// the provider. Shared by handleLoginOIDC and handleInviteRedeem — the only
// difference between an ordinary login and an invite redemption is which
// token, if any, travels with the attempt.
func redirectToProvider(w http.ResponseWriter, r *http.Request, login *identity.OIDCLogin, inviteToken string) {
	http.Redirect(w, r, login.BeginLogin(inviteToken), http.StatusFound) //nolint:gosec // G710: gosec's taint tracking flags this because inviteToken (request-derived, from the invite path or nowhere at all) reaches an http.Redirect argument — but BeginLogin never writes inviteToken into the URL it returns. That URL's host is oauth2.Config.Endpoint.AuthURL, which identity.NewOIDCLogin fixes once at startup from the OIDC discovery document (never from a request); only the server-generated state and PKCE challenge are added to it. inviteToken is stored server-side, keyed by that state (see loginAttemptStore in identity/store.go) — it never appears in the redirect target at all, so there is nothing here for an attacker to redirect anywhere.
}

// handleLoginOIDCCallback completes a login the provider is redirecting
// back from. It never distinguishes WHY a login failed in its response —
// see identity.ErrLoginStateInvalid and
// identity.ErrUnknownIdentityWithoutInvite's own comments for why
// OIDCLogin itself already collapses those reasons into one: a caller
// probing this endpoint must not learn which guess came closer.
func (a *API) handleLoginOIDCCallback(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()

	// previousSessionToken is whatever session cookie the browser already
	// presents, if any — Callback rotates it away regardless of whether it
	// currently resolves to anything (see its own comment on the
	// session-fixation defense that requires this). A cookie that fails to
	// parse or is simply absent is treated the same as "none": there is
	// nothing here for Callback to rotate away.
	var previousSessionToken string
	if cookie, err := r.Cookie(a.cfg.SessionCookieName); err == nil {
		previousSessionToken = cookie.Value
	}

	sessionToken, err := a.login.Callback(r.Context(), q.Get("state"), q.Get("code"), previousSessionToken)
	if err != nil {
		http.Error(w, "login failed", http.StatusUnauthorized)
		return
	}

	http.SetCookie(w, a.sessionCookie(sessionToken, 0))
	http.Redirect(w, r, "/", http.StatusFound)
}

// handleLogout ends the caller's session. It is idempotent by construction
// — SessionStore.Destroy does not error on a token that is already gone —
// and always clears the cookie regardless of whether one was actually
// still valid server-side.
func (a *API) handleLogout(w http.ResponseWriter, r *http.Request) {
	if cookie, err := r.Cookie(a.cfg.SessionCookieName); err == nil {
		if err := a.sessions.Destroy(cookie.Value); err != nil {
			http.Error(w, "logout failed", http.StatusInternalServerError)
			return
		}
	}
	http.SetCookie(w, a.sessionCookie("", -1))
	w.WriteHeader(http.StatusNoContent)
}

// sessionCookie builds the session cookie the routes above set (login) or
// clear (logout). Its three security attributes mirror ADR-0007 decision
// point 1 exactly: HttpOnly (JavaScript can never read a session token,
// unlike the CSRF double-submit cookie in csrf.go, which deliberately must
// stay readable), Secure (never sent over plain HTTP), SameSite=Strict
// (the browser never attaches it to a cross-site request in the first
// place — ahead of, and independent from, the CSRF check in csrf.go).
//
// maxAge follows net/http.Cookie's own documented convention: 0 means "no
// Max-Age attribute" (the default for a freshly issued cookie — expiry is
// enforced server-side by SessionStore.Lookup, not by the cookie's own
// lifetime), negative means "delete this cookie now".
func (a *API) sessionCookie(value string, maxAge int) *http.Cookie {
	return &http.Cookie{
		Name:     a.cfg.SessionCookieName,
		Value:    value,
		Path:     "/",
		HttpOnly: true,
		Secure:   true,
		SameSite: http.SameSiteStrictMode,
		MaxAge:   maxAge,
	}
}
