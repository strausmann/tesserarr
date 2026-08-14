package api

import (
	"net/http"

	"github.com/strausmann/tesserarr/internal/identity"
)

// LoginForwardAuthPath is the single entry path a trusted guard's headers
// authenticate against — no redirect, no external round trip, unlike
// LoginOIDCPath: the guard has already done the actual authentication by
// the time this request arrives (see the design doc, "Ablauf"). Sits under
// PrefixApp with MiddlewarePublic for the same reason LoginOIDCPath does:
// there is no session yet to check (see publicPaths in inventory.go).
//
// An invitation redemption reuses this SAME path with an "invite" query
// parameter (?invite=<token>) rather than a second, separate route — the
// design doc frames this as ONE entry path, not a pair like
// LoginOIDCPath/handleInviteRedeem, and a query parameter keeps that
// literally true: one Pattern, one publicPaths entry (verification point
// 6 in the design doc). Confirmed with team-lead: the invite token ending
// up in the guard's own access logs this way is not a NEW exposure — the
// existing "/invite/{token}" path (login.go, OIDC) already puts the same
// token in a URL path segment, which guard/proxy access logs capture
// exactly as readily as a query parameter. Nothing about choosing a query
// parameter here changes that already-accepted tradeoff.
const LoginForwardAuthPath = PrefixApp + "/login/forward-auth"

// handleLoginForwardAuth is the whole forward-auth flow in one request:
// trust check, header extraction, identity resolution (possibly minting a
// new user from an invite, in the SAME call — see
// identity.ForwardAuthLogin.Authenticate), session cookie, redirect to "/".
//
// TESSERARR_FORWARD_AUTH_ENABLED gates this at the very top: "ohne dies
// passiert nichts" (design doc, "Konfiguration") — an operator who never
// enables forward-auth gets a 404 here, not a route that silently accepts
// headers nobody configured a guard to send.
func (a *API) handleLoginForwardAuth(w http.ResponseWriter, r *http.Request) {
	if !a.cfg.ForwardAuthEnabled {
		http.NotFound(w, r)
		return
	}

	if !trustedForwardAuthSource(r, a.cfg) {
		http.Error(w, "forward-auth request not trusted", http.StatusForbidden)
		return
	}

	h := a.cfg.ForwardAuthHeaders
	subject := r.Header.Get(h.Subject)
	if subject == "" {
		http.Error(w, "forward-auth subject header missing", http.StatusUnauthorized)
		return
	}

	inviteToken := r.URL.Query().Get("invite")
	if len(inviteToken) > maxInviteTokenLen {
		http.Error(w, "invite token too long", http.StatusBadRequest)
		return
	}

	claims := identity.ForwardAuthClaims{
		Subject:       subject,
		Username:      r.Header.Get(h.Username),
		Name:          r.Header.Get(h.Name),
		Email:         r.Header.Get(h.Email),
		RoleHeaderRaw: r.Header.Get(h.Role),
	}

	var previousSessionToken string
	if cookie, err := r.Cookie(a.cfg.SessionCookieName); err == nil {
		previousSessionToken = cookie.Value
	}

	sessionToken, err := a.forwardAuthLogin.Authenticate(claims, inviteToken, previousSessionToken)
	if err != nil {
		// Deliberately one generic response for every rejection reason —
		// unknown identity without invite, empty subject, a database error
		// — the same principle handleLoginOIDCCallback already applies (see
		// its own comment): a caller probing this endpoint must not learn
		// which guess came closer.
		http.Error(w, "login failed", http.StatusUnauthorized)
		return
	}

	http.SetCookie(w, a.sessionCookie(sessionToken, 0))
	ensureCSRFCookie(w, r)
	http.Redirect(w, r, "/", http.StatusFound)
}
