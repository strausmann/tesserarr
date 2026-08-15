package identity

import (
	"context"
	"crypto"
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"database/sql"
	"encoding/base64"
	"encoding/json"
	"errors"
	"math/big"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/strausmann/tesserarr/internal/config"
	"github.com/strausmann/tesserarr/internal/invite"
)

// --- fake OIDC provider -----------------------------------------------
//
// A minimal, self-contained OpenID Connect provider used only by this
// file's tests. It implements just enough of the spec — discovery,
// authorization with PKCE, single-use codes, RS256-signed ID tokens, a
// JWKS endpoint — for OIDCLogin (a real client against a real provider) to
// complete a genuine round trip against it. Signing is done with the
// standard library only (crypto/rsa, crypto/sha256): the fake provider is
// test-only code, never compiled into the shipped binary, and hand-rolling
// three base64url segments is simpler to audit than reaching for a JOSE
// library import just for this.

// fakeOIDCProvider is a stand-in identity provider. Each test gets its own
// via newFakeOIDCProvider, so tests never share authorization codes.
type fakeOIDCProvider struct {
	server *httptest.Server
	key    *rsa.PrivateKey
	kid    string

	mu    sync.Mutex
	codes map[string]*fakeAuthCode

	// Test-only overrides for exercising OIDCLogin's error paths against a
	// provider that is otherwise perfectly well-behaved. Never touched by
	// the round-trip tests; only by the tests that specifically target one
	// of Callback's error-wrapping branches.
	omitIDToken      bool   // drop id_token from the token response entirely
	forceAudience    string // non-empty overrides the id token's aud claim
	forceEmailNumber bool   // encodes the email claim as a JSON number, which Callback's string-typed claims struct cannot decode
	// forceEmailVerifiedString, if non-empty ("true" or "false"), encodes
	// email_verified as a JSON STRING instead of the JSON boolean the spec
	// defines — the real-provider quirk oidcEmailVerifiedClaim
	// (email_verification.go) exists to tolerate. See
	// TestOIDCLoginRoundTripStoresEmailVerification's "provider sends
	// email_verified as a JSON string" subtest.
	forceEmailVerifiedString string
}

// fakeAuthCode is what the provider remembers about one /authorize
// request, keyed by the code it issued for it.
type fakeAuthCode struct {
	subject             string
	email               string
	emailVerified       string // "" (claim omitted), "true", or "false" — see handleAuthorize
	audience            string
	codeChallenge       string
	codeChallengeMethod string
	used                bool
}

func newFakeOIDCProvider(t *testing.T) *fakeOIDCProvider {
	t.Helper()
	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatalf("generate fake provider signing key: %v", err)
	}

	p := &fakeOIDCProvider{
		key:   key,
		kid:   "test-key-1",
		codes: make(map[string]*fakeAuthCode),
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/.well-known/openid-configuration", p.handleDiscovery)
	mux.HandleFunc("/authorize", p.handleAuthorize)
	mux.HandleFunc("/token", p.handleToken)
	mux.HandleFunc("/jwks", p.handleJWKS)
	p.server = httptest.NewServer(mux)
	t.Cleanup(p.server.Close)

	return p
}

func (p *fakeOIDCProvider) issuer() string { return p.server.URL }

func (p *fakeOIDCProvider) handleDiscovery(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, map[string]any{
		"issuer":                                p.server.URL,
		"authorization_endpoint":                p.server.URL + "/authorize",
		"token_endpoint":                        p.server.URL + "/token",
		"jwks_uri":                              p.server.URL + "/jwks",
		"response_types_supported":              []string{"code"},
		"subject_types_supported":               []string{"public"},
		"id_token_signing_alg_values_supported": []string{"RS256"},
		"scopes_supported":                      []string{"openid", "profile", "email"},
		"token_endpoint_auth_methods_supported": []string{"client_secret_basic", "client_secret_post"},
		"code_challenge_methods_supported":      []string{"S256"},
	})
}

// handleAuthorize instantly "approves" the request — there is no real user
// interface to drive in a test, so the round trip skips straight to
// issuing a code and redirecting back with it, exactly as a browser would
// end up doing after a human actually logged in. Which subject and email
// the code is for comes from the test-only test_subject/test_email query
// parameters a test adds on top of whatever OIDCLogin.BeginLogin produced
// (see completeLogin below) — real providers obviously have no such
// parameters; this exists purely so each test can pick its own identity
// without needing a shared, lockable field on the provider.
func (p *fakeOIDCProvider) handleAuthorize(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()

	subject := q.Get("test_subject")
	if subject == "" {
		subject = "default-test-subject"
	}

	code := randHex(16)
	p.mu.Lock()
	p.codes[code] = &fakeAuthCode{
		subject:             subject,
		email:               q.Get("test_email"),
		emailVerified:       q.Get("test_email_verified"),
		audience:            q.Get("client_id"),
		codeChallenge:       q.Get("code_challenge"),
		codeChallengeMethod: q.Get("code_challenge_method"),
	}
	p.mu.Unlock()

	redirect, err := url.Parse(q.Get("redirect_uri"))
	if err != nil {
		http.Error(w, "bad redirect_uri", http.StatusBadRequest)
		return
	}
	rq := redirect.Query()
	rq.Set("code", code)
	rq.Set("state", q.Get("state"))
	redirect.RawQuery = rq.Encode()

	// redirect_uri comes from this handler's OWN incoming request, not from
	// an external attacker: it is httptest-server-local test code standing
	// in for a third-party identity provider, and every caller in this
	// file is OIDCLogin itself, sending exactly the RedirectURL it was
	// configured with (see newTestOIDCLogin). A real OAuth provider's
	// authorize endpoint performs the very same redirect-to-registered-URI
	// step; it is inherent to the flow, not a defect this fake introduces.
	http.Redirect(w, r, redirect.String(), http.StatusFound) //nolint:gosec // G710: test-only fake provider, redirect_uri is our own request's own RedirectURL, not attacker input
}

// handleToken is the one place PKCE is actually enforced (per RFC 7636,
// the challenge is checked at token time, not at authorize time) and the
// one place a code is consumed. It deliberately does not check client
// authentication (Basic auth header vs. POST body — golang.org/x/oauth2
// auto-detects between the two and this fake accepts either by not caring)
// since client-secret validation is outside what Aufgabe 5's required
// tests cover; production code still sends the real secret to a real
// provider that does validate it.
func (p *fakeOIDCProvider) handleToken(w http.ResponseWriter, r *http.Request) {
	if err := r.ParseForm(); err != nil {
		http.Error(w, `{"error":"invalid_request"}`, http.StatusBadRequest)
		return
	}
	if r.PostFormValue("grant_type") != "authorization_code" {
		http.Error(w, `{"error":"unsupported_grant_type"}`, http.StatusBadRequest)
		return
	}

	code := r.PostFormValue("code")
	verifier := r.PostFormValue("code_verifier")

	p.mu.Lock()
	auth, ok := p.codes[code]
	if ok {
		if auth.used {
			ok = false
		} else {
			auth.used = true
		}
	}
	p.mu.Unlock()

	if !ok {
		http.Error(w, `{"error":"invalid_grant","error_description":"unknown or already-used code"}`, http.StatusBadRequest)
		return
	}
	if auth.codeChallengeMethod != "S256" || !pkceMatches(auth.codeChallenge, verifier) {
		http.Error(w, `{"error":"invalid_grant","error_description":"pkce verification failed"}`, http.StatusBadRequest)
		return
	}

	resp := map[string]any{
		"access_token": "fake-access-token",
		"token_type":   "Bearer",
		"expires_in":   3600,
	}
	if !p.omitIDToken {
		resp["id_token"] = p.issueIDToken(auth.subject, auth.email, auth.emailVerified, auth.audience)
	}
	writeJSON(w, resp)
}

func pkceMatches(challenge, verifier string) bool {
	sum := sha256.Sum256([]byte(verifier))
	return base64.RawURLEncoding.EncodeToString(sum[:]) == challenge
}

func (p *fakeOIDCProvider) handleJWKS(w http.ResponseWriter, _ *http.Request) {
	pub := &p.key.PublicKey
	writeJSON(w, map[string]any{
		"keys": []map[string]any{{
			"kty": "RSA",
			"kid": p.kid,
			"use": "sig",
			"alg": "RS256",
			"n":   base64.RawURLEncoding.EncodeToString(pub.N.Bytes()),
			"e":   base64.RawURLEncoding.EncodeToString(big.NewInt(int64(pub.E)).Bytes()),
		}},
	})
}

// issueIDToken hand-builds and RS256-signs a minimal ID token — the three
// base64url-encoded, dot-joined segments a JWT is defined as. No JOSE
// library is used; see the file-level comment for why.
func (p *fakeOIDCProvider) issueIDToken(subject, email, emailVerified, audience string) string {
	if p.forceAudience != "" {
		audience = p.forceAudience
	}

	now := time.Now().UTC()
	header := map[string]any{"alg": "RS256", "typ": "JWT", "kid": p.kid}
	claims := map[string]any{
		"iss": p.server.URL,
		"sub": subject,
		"aud": audience,
		"iat": now.Unix(),
		"exp": now.Add(10 * time.Minute).Unix(),
	}
	switch {
	case p.forceEmailNumber:
		// A JSON number where Callback's claims struct expects a string —
		// idToken.Claims(&claims) must fail to decode this.
		claims["email"] = 12345
	case email != "":
		claims["email"] = email
	}
	// emailVerified == "" falls through every case below, leaving the
	// claim out of the token entirely — exactly what a provider that never
	// sends email_verified at all does, and what emailVerificationFromClaim
	// must read back as EmailVerificationUnknown, not EmailVerificationFalse.
	switch {
	case p.forceEmailVerifiedString != "":
		// A JSON STRING where the spec defines a JSON boolean — the AWS
		// Cognito/Elastic quirk oidcEmailVerifiedClaim exists to tolerate
		// (see its own doc comment in email_verification.go).
		claims["email_verified"] = p.forceEmailVerifiedString
	case emailVerified == "true":
		claims["email_verified"] = true
	case emailVerified == "false":
		claims["email_verified"] = false
	}

	signingInput := mustB64JSON(header) + "." + mustB64JSON(claims)
	sum := sha256.Sum256([]byte(signingInput))
	sig, err := rsa.SignPKCS1v15(rand.Reader, p.key, crypto.SHA256, sum[:])
	if err != nil {
		// A signing failure here means the test's own RSA key is broken,
		// not something a caller can meaningfully recover from.
		panic("fake oidc provider: sign id token: " + err.Error())
	}
	return signingInput + "." + base64.RawURLEncoding.EncodeToString(sig)
}

func mustB64JSON(v any) string {
	b, err := json.Marshal(v)
	if err != nil {
		panic("fake oidc provider: marshal claims: " + err.Error())
	}
	return base64.RawURLEncoding.EncodeToString(b)
}

func writeJSON(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(v)
}

func randHex(n int) string {
	buf := make([]byte, n)
	_, _ = rand.Read(buf)
	const hexdigits = "0123456789abcdef"
	out := make([]byte, 2*n)
	for i, b := range buf {
		out[2*i] = hexdigits[b>>4]
		out[2*i+1] = hexdigits[b&0x0f]
	}
	return string(out)
}

// --- test helpers --------------------------------------------------------

// newTestOIDCLogin builds an OIDCLogin discovering against provider, over
// db and sessions. Tests reuse newTestSessionStore/createTestUser from
// session_test.go for the database side — same package, same fixtures.
func newTestOIDCLogin(t *testing.T, provider *fakeOIDCProvider, db *sql.DB, sessions *SessionStore) *OIDCLogin {
	t.Helper()
	return newTestOIDCLoginWithConfig(t, provider, db, sessions, OIDCConfig{})
}

// newTestOIDCLoginWithConfig is newTestOIDCLogin with room to override
// fields cfg carries — currently only DefaultRole (see
// TestCreateUserFromInviteSetsDefaultRoleAndEmail) — while still wiring up
// against provider the same way. IssuerURL/ClientID/ClientSecret/RedirectURL
// on cfg are always overwritten with the same fixed test values
// newTestOIDCLogin uses, so a caller only ever has to name the field it
// actually wants to vary.
func newTestOIDCLoginWithConfig(t *testing.T, provider *fakeOIDCProvider, db *sql.DB, sessions *SessionStore, cfg OIDCConfig) *OIDCLogin {
	t.Helper()
	cfg.IssuerURL = provider.issuer()
	cfg.ClientID = "tesserarr-test-client"
	cfg.ClientSecret = config.Secret("test-client-secret-does-not-matter")
	cfg.RedirectURL = "http://tesserarr.example/api/v1/app/login/oidc/callback"

	login, err := NewOIDCLogin(context.Background(), db, sessions, cfg)
	if err != nil {
		t.Fatalf("NewOIDCLogin() returned error: %v", err)
	}
	return login
}

// seedIdentity binds subject to userID as an already-known OIDC identity,
// simulating a user who logged in before.
func seedIdentity(t *testing.T, db *sql.DB, userID, subject string) {
	t.Helper()
	if _, err := db.Exec(
		`INSERT INTO identities (user_id, provider, subject, email) VALUES (?, ?, ?, ?)`,
		userID, oidcProviderName, subject, nil,
	); err != nil {
		t.Fatalf("seed identity: %v", err)
	}
}

// completeLogin drives one full authorization round trip against provider
// for authURL (as OIDCLogin.BeginLogin returns it), instructing the fake
// provider to authenticate as subject/email (either may be "" to accept
// the fake provider's defaults). It returns the state and authorization
// code the provider's redirect carried back — exactly what a browser would
// hand to Tesserarr's callback endpoint as query parameters.
func completeLogin(t *testing.T, authURL, subject, email string) (state, code string) {
	t.Helper()
	return completeLoginWithEmailVerified(t, authURL, subject, email, "")
}

// completeLoginWithEmailVerified is completeLogin plus a fourth,
// email_verified override — "" (the claim is never sent at all), "true", or
// "false" — for TestOIDCLoginRoundTripStoresEmailVerification, the only
// test that needs to control it. Kept as a separate function rather than
// widening completeLogin's own signature: every other test in this file
// calls completeLogin with exactly its existing four arguments, and none of
// them cares about email_verified at all — see fakeAuthCode.emailVerified
// and issueIDToken's own comments for why "" there must mean "omitted",
// not "false".
func completeLoginWithEmailVerified(t *testing.T, authURL, subject, email, emailVerified string) (state, code string) {
	t.Helper()

	u, err := url.Parse(authURL)
	if err != nil {
		t.Fatalf("parse auth URL %q: %v", authURL, err)
	}
	q := u.Query()
	if subject != "" {
		q.Set("test_subject", subject)
	}
	if email != "" {
		q.Set("test_email", email)
	}
	if emailVerified != "" {
		q.Set("test_email_verified", emailVerified)
	}
	u.RawQuery = q.Encode()

	client := &http.Client{
		CheckRedirect: func(_ *http.Request, _ []*http.Request) error {
			return http.ErrUseLastResponse
		},
	}
	resp, err := client.Get(u.String())
	if err != nil {
		t.Fatalf("authorize request: %v", err)
	}
	defer func() { _ = resp.Body.Close() }()

	loc := resp.Header.Get("Location")
	if loc == "" {
		t.Fatalf("authorize response carried no Location header (status %d)", resp.StatusCode)
	}
	locURL, err := url.Parse(loc)
	if err != nil {
		t.Fatalf("parse redirect location %q: %v", loc, err)
	}
	return locURL.Query().Get("state"), locURL.Query().Get("code")
}

// --- tests -----------------------------------------------------------

func TestOIDCLoginRoundTripCreatesAndRotatesSession(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	createTestUser(t, db, "user-returning")
	seedIdentity(t, db, "user-returning", "returning-subject")
	login := newTestOIDCLogin(t, provider, db, sessions)

	oldToken, err := sessions.Create("user-returning")
	if err != nil {
		t.Fatalf("seed pre-existing session: %v", err)
	}

	authURL := login.BeginLogin("")
	state, code := completeLogin(t, authURL, "returning-subject", "")

	newToken, err := login.Callback(context.Background(), state, code, oldToken)
	if err != nil {
		t.Fatalf("Callback() returned error: %v", err)
	}
	if newToken == "" {
		t.Fatal("Callback() returned an empty session token")
	}
	if newToken == oldToken {
		t.Fatal("Callback() returned the same token that was passed in as previousSessionToken — not rotated")
	}

	if _, err := sessions.Lookup(oldToken); !errors.Is(err, ErrSessionInvalid) {
		t.Errorf("old session token still resolves after Callback (err=%v), want ErrSessionInvalid — session was not rotated away", err)
	}

	gotUser, err := sessions.Lookup(newToken)
	if err != nil {
		t.Fatalf("Lookup(newToken) returned error: %v", err)
	}
	if gotUser != "user-returning" {
		t.Errorf("Lookup(newToken) resolved to user %q, want %q", gotUser, "user-returning")
	}
}

func TestOIDCLoginCallbackRejectsWrongStateWithoutRedeemingCode(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	createTestUser(t, db, "user-a")
	seedIdentity(t, db, "user-a", "subject-a")
	login := newTestOIDCLogin(t, provider, db, sessions)

	authURL := login.BeginLogin("")
	realState, code := completeLogin(t, authURL, "subject-a", "")

	_, err := login.Callback(context.Background(), "not-the-state-beginlogin-issued", code, "")
	if !errors.Is(err, ErrLoginStateInvalid) {
		t.Fatalf("Callback() with a wrong state returned %v, want ErrLoginStateInvalid", err)
	}

	// Proof that the wrong-state attempt above never reached the token
	// endpoint: the fake provider's codes are single-use (handleToken), so
	// if it HAD exchanged the code, this second attempt — using the real
	// state and the very same code — would now fail too.
	token, err := login.Callback(context.Background(), realState, code, "")
	if err != nil {
		t.Fatalf("Callback() with the real state returned error %v, want success — the earlier wrong-state attempt must not have consumed the code", err)
	}
	if token == "" {
		t.Fatal("Callback() with the real state returned an empty session token")
	}
}

func TestOIDCLoginCallbackRejectsMissingOrWrongPKCEVerifier(t *testing.T) {
	cases := []struct {
		name     string
		verifier string
	}{
		{"missing verifier", ""},
		{"wrong verifier", "not-the-verifier-beginlogin-generated"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			provider := newFakeOIDCProvider(t)
			sessions, db := newTestSessionStore(t, time.Hour)
			createTestUser(t, db, "user-a")
			seedIdentity(t, db, "user-a", "subject-a")
			login := newTestOIDCLogin(t, provider, db, sessions)

			authURL := login.BeginLogin("")
			state, code := completeLogin(t, authURL, "subject-a", "")

			// White-box: corrupt the verifier BeginLogin actually stored,
			// simulating a callback that never had (or lied about) the
			// real PKCE proof. A real caller can never do this — Callback
			// only ever reads the verifier back out of server-side state
			// (store.go) — so exercising this path requires reaching into
			// the package's own state, which same-package test files can.
			stored, ok := login.attempts.byState[state]
			if !ok {
				t.Fatalf("no pending login attempt stored for state %q", state)
			}
			stored.attempt.verifier = tc.verifier
			login.attempts.byState[state] = stored

			_, err := login.Callback(context.Background(), state, code, "")
			if err == nil {
				t.Fatal("Callback() succeeded with an invalid PKCE verifier, want an error")
			}
			if errors.Is(err, ErrLoginStateInvalid) {
				t.Fatalf("Callback() returned ErrLoginStateInvalid for a PKCE failure (err=%v) — the state itself was valid, only the verifier was wrong", err)
			}
		})
	}
}

func TestOIDCLoginCallbackRejectsExpiredLoginAttempt(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	createTestUser(t, db, "user-a")
	seedIdentity(t, db, "user-a", "subject-a")
	login := newTestOIDCLogin(t, provider, db, sessions)

	current := time.Now()
	login.attempts.now = func() time.Time { return current }

	authURL := login.BeginLogin("")
	state, code := completeLogin(t, authURL, "subject-a", "")

	current = current.Add(loginAttemptTTL + time.Minute)

	_, err := login.Callback(context.Background(), state, code, "")
	if !errors.Is(err, ErrLoginStateInvalid) {
		t.Fatalf("Callback() after the login attempt expired returned %v, want ErrLoginStateInvalid", err)
	}
}

func TestOIDCLoginCallbackRejectsUnknownIdentityWithoutInvite(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	login := newTestOIDCLogin(t, provider, db, sessions)

	authURL := login.BeginLogin("") // no invite token
	state, code := completeLogin(t, authURL, "never-seen-subject", "person@example.com")

	_, err := login.Callback(context.Background(), state, code, "")
	if !errors.Is(err, ErrUnknownIdentityWithoutInvite) {
		t.Fatalf("Callback() for an unknown subject without an invite returned %v, want ErrUnknownIdentityWithoutInvite", err)
	}

	// ADR-0004 is explicit that a provider login alone never grants access
	// — confirm no user or identity was left behind by the rejected login.
	var count int
	if err := db.QueryRow(`SELECT COUNT(*) FROM identities WHERE subject = ?`, "never-seen-subject").Scan(&count); err != nil {
		t.Fatalf("count identities: %v", err)
	}
	if count != 0 {
		t.Errorf("identities table has %d row(s) for the rejected subject, want 0", count)
	}
}

func TestOIDCLoginCallbackWithValidInviteCreatesUserAndRedeemsInvite(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	login := newTestOIDCLogin(t, provider, db, sessions)

	inviteToken, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}

	authURL := login.BeginLogin(inviteToken)
	state, code := completeLogin(t, authURL, "brand-new-subject", "person@example.com")

	sessionToken, err := login.Callback(context.Background(), state, code, "")
	if err != nil {
		t.Fatalf("Callback() returned error: %v", err)
	}
	if sessionToken == "" {
		t.Fatal("Callback() returned an empty session token")
	}

	userID, err := sessions.Lookup(sessionToken)
	if err != nil {
		t.Fatalf("Lookup(sessionToken) returned error: %v", err)
	}

	var storedSubject, storedEmail string
	if err := db.QueryRow(
		`SELECT subject, email FROM identities WHERE user_id = ? AND provider = ?`,
		userID, oidcProviderName,
	).Scan(&storedSubject, &storedEmail); err != nil {
		t.Fatalf("read identity row: %v", err)
	}
	if storedSubject != "brand-new-subject" {
		t.Errorf("stored subject = %q, want %q", storedSubject, "brand-new-subject")
	}
	if storedEmail != "person@example.com" {
		t.Errorf("stored email = %q, want %q", storedEmail, "person@example.com")
	}

	// The invitation must now be spent.
	if err := invite.Redeem(db, inviteToken, userID); !errors.Is(err, invite.ErrAlreadyRedeemed) {
		t.Errorf("invite.Redeem() a second time returned %v, want invite.ErrAlreadyRedeemed", err)
	}
}

// --- error paths ---------------------------------------------------------
//
// The tests above cover the flow ADR-0004/ADR-0007 actually care about.
// These cover the remaining error-wrapping branches Callback and
// createUserFromInvite go through when something around them — the
// provider, the database — misbehaves, so that a genuine infrastructure
// failure is never silently swallowed or, worse, misreported as one of the
// two auth-relevant sentinels (ErrLoginStateInvalid,
// ErrUnknownIdentityWithoutInvite).

func TestNewOIDCLoginFailsWhenDiscoveryFails(t *testing.T) {
	badProvider := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		http.NotFound(w, nil)
	}))
	t.Cleanup(badProvider.Close)

	sessions, db := newTestSessionStore(t, time.Hour)

	_, err := NewOIDCLogin(context.Background(), db, sessions, OIDCConfig{
		IssuerURL:    badProvider.URL,
		ClientID:     "client",
		ClientSecret: config.Secret("secret"),
		RedirectURL:  "http://tesserarr.example/callback",
	})
	if err == nil {
		t.Fatal("NewOIDCLogin() succeeded against a provider with no discovery document, want an error")
	}
}

func TestOIDCLoginCallbackRejectsTokenResponseWithoutIDToken(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	provider.omitIDToken = true
	sessions, db := newTestSessionStore(t, time.Hour)
	createTestUser(t, db, "user-a")
	seedIdentity(t, db, "user-a", "subject-a")
	login := newTestOIDCLogin(t, provider, db, sessions)

	authURL := login.BeginLogin("")
	state, code := completeLogin(t, authURL, "subject-a", "")

	_, err := login.Callback(context.Background(), state, code, "")
	if err == nil {
		t.Fatal("Callback() succeeded despite a token response without an id_token, want an error")
	}
	if !strings.Contains(err.Error(), "id_token") {
		t.Errorf("Callback() error = %v, want it to mention the missing id_token", err)
	}
}

func TestOIDCLoginCallbackRejectsIDTokenWithWrongAudience(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	provider.forceAudience = "someone-elses-client-id"
	sessions, db := newTestSessionStore(t, time.Hour)
	createTestUser(t, db, "user-a")
	seedIdentity(t, db, "user-a", "subject-a")
	login := newTestOIDCLogin(t, provider, db, sessions)

	authURL := login.BeginLogin("")
	state, code := completeLogin(t, authURL, "subject-a", "")

	_, err := login.Callback(context.Background(), state, code, "")
	if err == nil {
		t.Fatal("Callback() succeeded with an id token issued for a different audience, want an error")
	}
	if errors.Is(err, ErrLoginStateInvalid) || errors.Is(err, ErrUnknownIdentityWithoutInvite) {
		t.Errorf("Callback() returned one of the auth-decision sentinels (err=%v) for a token-verification failure — those are reserved for the state and invite checks", err)
	}
}

func TestOIDCLoginCallbackRejectsUnparseableClaims(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	provider.forceEmailNumber = true
	sessions, db := newTestSessionStore(t, time.Hour)
	createTestUser(t, db, "user-a")
	seedIdentity(t, db, "user-a", "subject-a")
	login := newTestOIDCLogin(t, provider, db, sessions)

	authURL := login.BeginLogin("")
	state, code := completeLogin(t, authURL, "subject-a", "")

	_, err := login.Callback(context.Background(), state, code, "")
	if err == nil {
		t.Fatal("Callback() succeeded despite an id token whose email claim is a number, not a string, want an error")
	}
	if !strings.Contains(err.Error(), "claims") {
		t.Errorf("Callback() error = %v, want it to mention claim parsing", err)
	}
}

// TestOIDCLoginCallbackFailsWhenIdentityLookupErrors closes the database
// between the authorization round trip and the callback, so
// resolveUser's lookupIdentity fails with a genuine database error rather
// than the expected-and-handled sql.ErrNoRows a first-time subject
// produces.
func TestOIDCLoginCallbackFailsWhenIdentityLookupErrors(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	login := newTestOIDCLogin(t, provider, db, sessions)

	authURL := login.BeginLogin("")
	state, code := completeLogin(t, authURL, "subject-a", "")

	if err := db.Close(); err != nil {
		t.Fatalf("close database: %v", err)
	}

	_, err := login.Callback(context.Background(), state, code, "")
	if err == nil {
		t.Fatal("Callback() succeeded despite a closed database, want an error")
	}
	if errors.Is(err, ErrUnknownIdentityWithoutInvite) {
		t.Errorf("Callback() returned ErrUnknownIdentityWithoutInvite for a database error (err=%v) — a genuine lookup failure must not be reported as a rejected login", err)
	}
}

// TestOIDCLoginCallbackFailsWhenSessionRotationFails backs the session
// store with an already-closed database while leaving the identity
// database open, isolating Callback's final error-wrapping branch (around
// SessionStore.Rotate) from every branch that runs before it.
func TestOIDCLoginCallbackFailsWhenSessionRotationFails(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	_, db := newTestSessionStore(t, time.Hour)
	createTestUser(t, db, "user-a")
	seedIdentity(t, db, "user-a", "subject-a")

	brokenSessions, brokenSessionsDB := newTestSessionStore(t, time.Hour)
	if err := brokenSessionsDB.Close(); err != nil {
		t.Fatalf("close broken sessions database: %v", err)
	}

	login := newTestOIDCLogin(t, provider, db, brokenSessions)

	authURL := login.BeginLogin("")
	state, code := completeLogin(t, authURL, "subject-a", "")

	_, err := login.Callback(context.Background(), state, code, "")
	if err == nil {
		t.Fatal("Callback() succeeded despite a broken session store, want an error")
	}
	if !strings.Contains(err.Error(), "start session") {
		t.Errorf("Callback() error = %v, want it to mention session creation failing", err)
	}
}

// TestOIDCLoginCreateUserFromInviteFailsWhenUserInsertFails drops the
// users table before the invite-redeeming login runs, isolating
// createUserFromInvite's error-wrapping branch around "INSERT INTO users"
// from every other failure the function can hit.
func TestOIDCLoginCreateUserFromInviteFailsWhenUserInsertFails(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	login := newTestOIDCLogin(t, provider, db, sessions)

	inviteToken, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}
	if _, err := db.Exec(`DROP TABLE users`); err != nil {
		t.Fatalf("drop users table: %v", err)
	}

	authURL := login.BeginLogin(inviteToken)
	state, code := completeLogin(t, authURL, "new-subject", "")

	_, err = login.Callback(context.Background(), state, code, "")
	if err == nil {
		t.Fatal("Callback() succeeded despite a missing users table, want an error")
	}
	if errors.Is(err, ErrUnknownIdentityWithoutInvite) {
		t.Errorf("Callback() reported ErrUnknownIdentityWithoutInvite for a schema failure (err=%v) — that sentinel is reserved for an actually rejected invitation", err)
	}
}

// TestOIDCLoginCreateUserFromInviteFailsWhenIdentityInsertFails installs a
// trigger that blocks every INSERT into identities while leaving SELECT
// (and thus resolveUser's initial lookup) untouched, isolating
// createUserFromInvite's error-wrapping branch around "INSERT INTO
// identities". Dropping the identities table outright would not isolate
// this branch: resolveUser's own lookup queries the same table, so a
// missing table would be caught one branch earlier instead.
func TestOIDCLoginCreateUserFromInviteFailsWhenIdentityInsertFails(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	login := newTestOIDCLogin(t, provider, db, sessions)

	inviteToken, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}
	if _, err := db.Exec(`
		CREATE TRIGGER block_identity_insert
		BEFORE INSERT ON identities
		BEGIN
			SELECT RAISE(ABORT, 'test: identities insert blocked');
		END;
	`); err != nil {
		t.Fatalf("install blocking trigger: %v", err)
	}

	authURL := login.BeginLogin(inviteToken)
	state, code := completeLogin(t, authURL, "new-subject", "")

	_, err = login.Callback(context.Background(), state, code, "")
	if err == nil {
		t.Fatal("Callback() succeeded despite a trigger blocking the identity insert, want an error")
	}
	if errors.Is(err, ErrUnknownIdentityWithoutInvite) {
		t.Errorf("Callback() reported ErrUnknownIdentityWithoutInvite for a schema failure (err=%v) — that sentinel is reserved for an actually rejected invitation", err)
	}

	// The transaction must have rolled back — no half-created user left
	// behind by the failed identity insert.
	var count int
	if err := db.QueryRow(`SELECT COUNT(*) FROM users`).Scan(&count); err != nil {
		t.Fatalf("count users: %v", err)
	}
	if count != 0 {
		t.Errorf("users table has %d row(s) after a rolled-back user creation, want 0", count)
	}
}

// TestOIDCLoginCreateUserFromInviteRollsBackWhenRedeemFails is a direct
// regression test for the security audit finding
// (~/security-audit-skill/tesserarr/run-1/findings.json, "Invite-gate
// bypass: an untransacted compensating DELETE can leave a permanent,
// never-actually-redeemed account if it fails to run").
//
// createUserFromInvite used to commit the users/identities INSERTs first,
// then attempt invite.Redeem, and if THAT failed, run a single, best-effort
// `DELETE FROM users` whose own error was discarded — the only cleanup for
// a bogus, expired, revoked or already-redeemed token. A synchronous test
// that simply calls Callback with a bogus token could never have caught
// the bug: on a normal call the DELETE runs and succeeds, cleaning up
// exactly as intended. The actual vulnerability only shows up when that
// DELETE fails to run at all (a process crash landing in the narrow window
// between the commit and the DELETE) or fails outright (a transient DB
// error) — this test simulates the latter with a blocking trigger, which
// the audit itself names as a valid trigger condition alongside a crash.
//
// Before the fix (INSERTs committed separately from Redeem): the trigger
// blocks the compensating DELETE, so the previously committed user row
// survives — an orphaned account that was never authorized by a
// successful invite redemption, in violation of ADR-0004. After the fix
// (Redeem inside the same transaction as the INSERTs, no separate DELETE
// needed): Redeem's failure for the bogus token means the transaction is
// rolled back before anything is ever committed, so the blocking trigger
// never even matters — there is nothing left to delete.
func TestOIDCLoginCreateUserFromInviteRollsBackWhenRedeemFails(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	login := newTestOIDCLogin(t, provider, db, sessions)

	if _, err := db.Exec(`
		CREATE TRIGGER block_user_delete
		BEFORE DELETE ON users
		BEGIN
			SELECT RAISE(ABORT, 'test: users delete blocked');
		END;
	`); err != nil {
		t.Fatalf("install blocking trigger: %v", err)
	}

	// A bogus, never-issued token — invite.Redeem will reject it
	// deterministically (ErrNotFound).
	authURL := login.BeginLogin("this-invite-token-was-never-created")
	state, code := completeLogin(t, authURL, "orphan-candidate-subject", "")

	_, err := login.Callback(context.Background(), state, code, "")
	if err == nil {
		t.Fatal("Callback() succeeded with a bogus invite token, want an error")
	}
	if !errors.Is(err, ErrUnknownIdentityWithoutInvite) {
		t.Errorf("Callback() error = %v, want it to wrap ErrUnknownIdentityWithoutInvite", err)
	}

	// The point of this test: no user (and, via the FK, no identity) must
	// survive a rejected redemption — regardless of whether the
	// compensating cleanup this fix removes would have succeeded.
	var count int
	if err := db.QueryRow(`SELECT COUNT(*) FROM users`).Scan(&count); err != nil {
		t.Fatalf("count users: %v", err)
	}
	if count != 0 {
		t.Errorf("users table has %d row(s) after a rejected invite redemption, want 0 — "+
			"an account was left behind that never actually redeemed an invitation", count)
	}

	// Counter-review addition, ported from an independent second
	// implementation of the same audit fix: the assertion above proves the
	// failed attempt left no row behind, but not that the SAME subject can
	// still redeem a REAL invite afterward — the two-step, pre-fix version
	// could in principle leave the subject in some half-created state that
	// blocks, or worse silently bypasses, a later, genuine redemption.
	//
	// IMPORTANT — verified, not assumed: a bare "the second Callback()
	// succeeds and returns a session" assertion does NOT distinguish this
	// fix from its absence. Checked directly: run against the pre-fix,
	// two-step implementation (with this test's own blocking trigger still
	// in place), the first attempt's orphaned identity row survives
	// exactly as the assertion above already catches — but a bare
	// "succeeds" check on the second attempt would ALSO pass, for the
	// WRONG reason: resolveUser's known-subject shortcut recognizes the
	// leftover identity and mints a session WITHOUT ever consulting
	// realToken at all, silently reproducing the exact vulnerability this
	// fix closes rather than proving it fixed. A bare success/session
	// check cannot tell "redeemed cleanly" from "let in via the orphaned
	// identity from the first, rejected attempt" apart — both return a
	// non-empty session token.
	//
	// The assertion that actually distinguishes the two: realToken itself
	// must have been consumed BY THIS SPECIFIC new user — invitations.redeemed_by
	// must equal the second attempt's own userID. In the pre-fix scenario
	// above, the known-subject shortcut never touches realToken at all, so
	// it stays unredeemed (redeemed_by IS NULL) even though a session was
	// returned — which is exactly what the query below would catch.
	realToken, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}
	authURL2 := login.BeginLogin(realToken)
	state2, code2 := completeLogin(t, authURL2, "orphan-candidate-subject", "")

	sessionToken, err := login.Callback(context.Background(), state2, code2, "")
	if err != nil {
		t.Fatalf("second Callback() with a genuine invite returned error: %v — "+
			"the rejected first attempt must not block a later, real redemption", err)
	}
	if sessionToken == "" {
		t.Fatal("second Callback() with a genuine invite returned no session token")
	}

	userID2, err := sessions.Lookup(sessionToken)
	if err != nil {
		t.Fatalf("sessions.Lookup() on the second session token returned error: %v", err)
	}

	var redeemedByThisUser int
	if err := db.QueryRow(
		`SELECT COUNT(*) FROM invitations WHERE redeemed_by = ?`, userID2,
	).Scan(&redeemedByThisUser); err != nil {
		t.Fatalf("count invitations redeemed by the second user: %v", err)
	}
	if redeemedByThisUser != 1 {
		t.Errorf("invitations redeemed by the second attempt's user = %d, want 1 — "+
			"the second session must come from genuinely redeeming realToken, not from the "+
			"first, rejected attempt's identity surviving under the known-subject shortcut", redeemedByThisUser)
	}
}

// TestOIDCLoginCreateUserFromInviteChoosesDisplayName exercises
// displayName's (and, for the empty-email case, nullable's) branches
// through the real invite-redeeming login flow, rather than calling the
// unexported helpers directly — what ends up in users.display_name is the
// observable behavior that actually matters.
func TestOIDCLoginCreateUserFromInviteChoosesDisplayName(t *testing.T) {
	const subject = "some-subject-value"
	cases := []struct {
		name     string
		email    string
		wantName string
	}{
		{"proper email uses the local part", "person@example.com", "person"},
		{"email without an at sign falls back to the whole value", "not-an-email", "not-an-email"},
		{"email starting with an at sign falls back to the whole value", "@example.com", "@example.com"},
		{"no email falls back to the subject", "", subject},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			provider := newFakeOIDCProvider(t)
			sessions, db := newTestSessionStore(t, time.Hour)
			login := newTestOIDCLogin(t, provider, db, sessions)

			inviteToken, err := invite.Create(db, 0)
			if err != nil {
				t.Fatalf("invite.Create() returned error: %v", err)
			}

			authURL := login.BeginLogin(inviteToken)
			state, code := completeLogin(t, authURL, subject, tc.email)

			sessionToken, err := login.Callback(context.Background(), state, code, "")
			if err != nil {
				t.Fatalf("Callback() returned error: %v", err)
			}
			userID, err := sessions.Lookup(sessionToken)
			if err != nil {
				t.Fatalf("Lookup() returned error: %v", err)
			}

			var gotName string
			if err := db.QueryRow(`SELECT display_name FROM users WHERE id = ?`, userID).Scan(&gotName); err != nil {
				t.Fatalf("read display_name: %v", err)
			}
			if gotName != tc.wantName {
				t.Errorf("display_name = %q, want %q", gotName, tc.wantName)
			}
		})
	}
}

// TestCreateUserFromInviteSetsDefaultRoleAndEmail is Aufgabe 4's proof that
// the role system is not forward-auth-specific: a brand-new OIDC user gets
// email and role written at creation too, exactly like a brand-new
// forward-auth user (forwardauth.go's createUserFromInvite) — otherwise
// "tesserarr user promote" (Aufgabe 10) would have nothing to promote for a
// deployment that never configures a guard at all, which the design doc
// explicitly rules out ("um überhaupt den ersten Administrator zu haben,
// bevor irgendein Wächter konfiguriert ist").
func TestCreateUserFromInviteSetsDefaultRoleAndEmail(t *testing.T) {
	db, _, login := newOIDCLoginWithDefaultRole(t, "familie")
	inviteToken := createTestInvite(t, db)

	_, err := login.resolveUser("new-subject", "friend@example.org", EmailVerificationUnknown, inviteToken)
	if err != nil {
		t.Fatalf("resolveUser() returned error: %v", err)
	}

	var email sql.NullString
	var role string
	if err := db.QueryRow(
		`SELECT email, role FROM users WHERE id = (SELECT user_id FROM identities WHERE provider = 'oidc' AND subject = 'new-subject')`,
	).Scan(&email, &role); err != nil {
		t.Fatalf("read created user: %v", err)
	}
	if !email.Valid || email.String != "friend@example.org" {
		t.Errorf("email = %+v, want friend@example.org", email)
	}
	if role != "familie" {
		t.Errorf("role = %q, want familie", role)
	}
}

// TestOIDCLoginRoundTripStoresEmailVerification is the end-to-end proof
// email_verification.go's plumbing (Callback's claims struct →
// emailVerificationFromClaim → createUserFromInvite's INSERT → the real
// identities.email_verified column) actually wires together through a
// genuine Callback round trip against the fake provider — not just the
// unit-level pieces email_verification_test.go already covers in
// isolation. This is the regression test for the bug a security review
// caught in an earlier version of this feature: oidc.go never read
// email_verified from the ID token at all, so every OIDC identity's email
// printed as "verified" (cmd/tesserarr/main.go) regardless of what — if
// anything — the provider had actually confirmed.
func TestOIDCLoginRoundTripStoresEmailVerification(t *testing.T) {
	tests := []struct {
		name                     string
		emailVerified            string // fed to completeLoginWithEmailVerified's test_email_verified query param
		forceEmailVerifiedString string // set on the provider instead, for the JSON-string-not-boolean subtest
		want                     EmailVerification
	}{
		{"provider confirms the email", "true", "", EmailVerificationTrue},
		{"provider explicitly denies confirmation", "false", "", EmailVerificationFalse},
		{"provider omits the claim entirely", "", "", EmailVerificationUnknown},
		{"provider sends email_verified as a JSON string, not a boolean", "", "true", EmailVerificationTrue},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			provider := newFakeOIDCProvider(t)
			provider.forceEmailVerifiedString = tc.forceEmailVerifiedString
			sessions, db := newTestSessionStore(t, time.Hour)
			login := newTestOIDCLogin(t, provider, db, sessions)

			inviteToken, err := invite.Create(db, 0)
			if err != nil {
				t.Fatalf("invite.Create() returned error: %v", err)
			}

			authURL := login.BeginLogin(inviteToken)
			const subject = "email-verification-test-subject"
			state, code := completeLoginWithEmailVerified(t, authURL, subject, "person@example.com", tc.emailVerified)

			if _, err := login.Callback(context.Background(), state, code, ""); err != nil {
				t.Fatalf("Callback() returned error: %v", err)
			}

			var raw sql.NullInt64
			if err := db.QueryRow(
				`SELECT email_verified FROM identities WHERE provider = 'oidc' AND subject = ?`, subject,
			).Scan(&raw); err != nil {
				t.Fatalf("read identities.email_verified: %v", err)
			}
			if got := emailVerificationFromNullInt64(raw); got != tc.want {
				t.Errorf("identities.email_verified = %v (raw = %+v), want %v", got, raw, tc.want)
			}
		})
	}
}

// TestOIDCLoginRoundTripNeverUpdatesEmailVerificationOnReturningLogin
// proves the staleness rule EmailVerification's own doc comment documents:
// identities.email_verified, like identities.email itself, is written ONCE
// at first bind and never refreshed on a later login — a RETURNING login
// reporting a different email_verified value than the one recorded at
// creation must not change what is stored.
func TestOIDCLoginRoundTripNeverUpdatesEmailVerificationOnReturningLogin(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	login := newTestOIDCLogin(t, provider, db, sessions)

	inviteToken, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}
	const subject = "returning-email-verification-subject"

	// First login: the provider confirms the email.
	authURL := login.BeginLogin(inviteToken)
	state, code := completeLoginWithEmailVerified(t, authURL, subject, "person@example.com", "true")
	if _, err := login.Callback(context.Background(), state, code, ""); err != nil {
		t.Fatalf("first Callback() returned error: %v", err)
	}

	// Second, RETURNING login: the very same fake provider now reports NO
	// email_verified claim at all (a config change on the provider's side,
	// or simply a provider that only sends it on first consent — either
	// way, resolveUser's returning-identity branch must never touch the
	// column again to reflect it). No invite token needed this time —
	// resolveUser's "case err == nil" branch (an already-bound identity)
	// never consults inviteToken at all, see its own doc comment.
	authURL = login.BeginLogin("")
	state, code = completeLoginWithEmailVerified(t, authURL, subject, "person@example.com", "")
	if _, err := login.Callback(context.Background(), state, code, ""); err != nil {
		t.Fatalf("second Callback() returned error: %v", err)
	}

	var raw sql.NullInt64
	if err := db.QueryRow(
		`SELECT email_verified FROM identities WHERE provider = 'oidc' AND subject = ?`, subject,
	).Scan(&raw); err != nil {
		t.Fatalf("read identities.email_verified: %v", err)
	}
	if got := emailVerificationFromNullInt64(raw); got != EmailVerificationTrue {
		t.Errorf("identities.email_verified after a returning login = %v (raw = %+v), want it to still be EmailVerificationTrue from the FIRST login, unchanged", got, raw)
	}
}

// newOIDCLoginWithDefaultRole is newTestOIDCLogin plus an OIDCConfig.DefaultRole
// override — its own fresh provider and session store, since defaultRole is
// fixed for the lifetime of an OIDCLogin (see NewOIDCLogin) and no other
// test in this file needs one configured.
func newOIDCLoginWithDefaultRole(t *testing.T, defaultRole string) (*sql.DB, *SessionStore, *OIDCLogin) {
	t.Helper()
	sessions, db := newTestSessionStore(t, time.Hour)
	provider := newFakeOIDCProvider(t)
	login := newTestOIDCLoginWithConfig(t, provider, db, sessions, OIDCConfig{DefaultRole: defaultRole})
	return db, sessions, login
}

// createTestInvite is a one-line wrapper around invite.Create with a
// zero TTL (never expires) — the same call every other test in this file
// already repeats inline; named here purely so
// TestCreateUserFromInviteSetsDefaultRoleAndEmail reads as "get me a valid
// invite" rather than repeating the invite package's own signature.
func createTestInvite(t *testing.T, db *sql.DB) string {
	t.Helper()
	token, err := invite.Create(db, 0)
	if err != nil {
		t.Fatalf("invite.Create() returned error: %v", err)
	}
	return token
}

// --- Befund B: a RETURNING OIDC login must recompute the effective role,
// same as forward-auth's updateProfile already does on every entry
// (forwardauth.go) --------------------------------------------------------
//
// Before oidc.go's resolveUser gained updateRole, its "case err == nil"
// branch (an already-known identity — a returning login) returned userID
// straight away, never touching users.role at all. A user who signs in
// exclusively via OIDC therefore had NO login path that ever applied an
// operator's PromoteUserRole assignment (promotion OR revocation) — only
// createUserFromInvite (a brand-new user) and forward-auth's updateProfile
// ever wrote role. setUserLocalRoleForTest below (defined in
// forwardauth_test.go, same package) is reused here for the identical
// reason it exists there: only PromoteUserRole is allowed to write
// role_local in the real system; tests set it up directly.

// TestResolveUserAppliesOperatorAssignedRoleOnReturningLogin proves a
// RETURNING oidc identity (seedIdentity + createTestUser, never through
// createUserFromInvite) picks up an operator's role_local assignment on
// this very login — the promotion half of Befund B.
func TestResolveUserAppliesOperatorAssignedRoleOnReturningLogin(t *testing.T) {
	db, _, login := newOIDCLoginWithDefaultRole(t, "gast")
	createTestUser(t, db, "user-existing")
	seedIdentity(t, db, "user-existing", "returning-subject")
	setUserLocalRoleForTest(t, db, "user-existing", "admin")

	if _, err := login.resolveUser("returning-subject", "", EmailVerificationUnknown, ""); err != nil {
		t.Fatalf("resolveUser() returned error: %v", err)
	}

	if got := readUserRole(t, db, "user-existing"); got != "admin" {
		t.Errorf("role after returning oidc login = %q, want admin (the operator's role_local assignment)", got)
	}
}

// TestResolveUserAppliesRoleRevocationOnReturningLogin is the other half:
// an operator taking rights AWAY (role_local reset to "") must reach the
// user's next OIDC login too, not just their next forward-auth one.
// Without Befund B's fix, "admin" from the first login would have survived
// forever — resolveUser's returning-identity branch never re-read
// role_local at all.
func TestResolveUserAppliesRoleRevocationOnReturningLogin(t *testing.T) {
	db, _, login := newOIDCLoginWithDefaultRole(t, "gast")
	createTestUser(t, db, "user-existing")
	seedIdentity(t, db, "user-existing", "returning-subject")
	setUserLocalRoleForTest(t, db, "user-existing", "admin")

	if _, err := login.resolveUser("returning-subject", "", EmailVerificationUnknown, ""); err != nil {
		t.Fatalf("first resolveUser() returned error: %v", err)
	}
	if got := readUserRole(t, db, "user-existing"); got != "admin" {
		t.Fatalf("role after first login = %q, want admin", got)
	}

	setUserLocalRoleForTest(t, db, "user-existing", "") // the operator revokes access

	if _, err := login.resolveUser("returning-subject", "", EmailVerificationUnknown, ""); err != nil {
		t.Fatalf("second resolveUser() returned error: %v", err)
	}
	if got := readUserRole(t, db, "user-existing"); got != "gast" {
		t.Errorf("role after revocation and a second login = %q, want gast (the default — \"admin\" must not survive)", got)
	}
}

// TestResolveUserFailsWhenRoleUpdateFails isolates updateProfile's own
// UPDATE-statement failure branch, mirroring forwardauth_test.go's
// TestAuthenticateFailsWhenProfileUpdateFails for the identical reason: a
// blocking trigger fails only the write, leaving the preceding role_local
// SELECT (currentLocalRole) untouched. Role and email are written by the
// SAME statement (updateProfile), so this one trigger already isolates the
// failure branch for both fields — no separate email-update-fails test is
// needed alongside it.
func TestResolveUserFailsWhenRoleUpdateFails(t *testing.T) {
	db, _, login := newOIDCLoginWithDefaultRole(t, "gast")
	createTestUser(t, db, "user-existing")
	seedIdentity(t, db, "user-existing", "returning-subject")

	if _, err := db.Exec(`
		CREATE TRIGGER block_oidc_role_update
		BEFORE UPDATE ON users
		BEGIN
			SELECT RAISE(ABORT, 'test: users update blocked');
		END;
	`); err != nil {
		t.Fatalf("install blocking trigger: %v", err)
	}

	if _, err := login.resolveUser("returning-subject", "", EmailVerificationUnknown, ""); err == nil {
		t.Fatal("resolveUser() succeeded despite a trigger blocking the role update, want an error")
	}
}

// TestOIDCLoginCallbackAppliesRoleThroughFullRoundTrip is the one
// end-to-end check that Callback — not just resolveUser directly — reaches
// updateProfile, exercising the same fake-provider round trip every other
// Callback test in this file already uses.
func TestOIDCLoginCallbackAppliesRoleThroughFullRoundTrip(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	createTestUser(t, db, "user-existing")
	seedIdentity(t, db, "user-existing", "returning-subject")
	setUserLocalRoleForTest(t, db, "user-existing", "admin")
	login := newTestOIDCLoginWithConfig(t, provider, db, sessions, OIDCConfig{DefaultRole: "gast"})

	authURL := login.BeginLogin("")
	state, code := completeLogin(t, authURL, "returning-subject", "")
	if _, err := login.Callback(context.Background(), state, code, ""); err != nil {
		t.Fatalf("Callback() returned error: %v", err)
	}

	if got := readUserRole(t, db, "user-existing"); got != "admin" {
		t.Errorf("role after Callback() = %q, want admin", got)
	}
}

// --- returning OIDC login refreshes users.email --------------------------
//
// Before this, resolveUser's "case err == nil" branch (an already-known
// identity) only ever called updateProfile for the role — the email claim
// it already receives as a parameter (used by the brand-new-user branch via
// createUserFromInvite) was silently dropped on every RETURNING login. Once
// the self-service email write path was removed, an OIDC-only user had no
// way left to ever correct a stale address: not through the UI (removed on
// purpose), not through forward-auth (no such identity to log in with), and
// — until now — not by simply logging back in via OIDC either, even though
// the provider hands the current claim on every single login.
//
// readUserEmail mirrors readUserRole (forwardauth_test.go, same package):
// a one-line helper reading back what the tests below actually assert on.
func readUserEmail(t *testing.T, db *sql.DB, userID string) sql.NullString {
	t.Helper()
	var email sql.NullString
	if err := db.QueryRow(`SELECT email FROM users WHERE id = ?`, userID).Scan(&email); err != nil {
		t.Fatalf("read email: %v", err)
	}
	return email
}

// TestResolveUserRefreshesEmailOnReturningLogin proves a RETURNING oidc
// identity (seedIdentity + createTestUser, never through
// createUserFromInvite, so users.email starts out NULL) picks up the
// provider's current email claim on this very login.
func TestResolveUserRefreshesEmailOnReturningLogin(t *testing.T) {
	db, _, login := newOIDCLoginWithDefaultRole(t, "gast")
	createTestUser(t, db, "user-existing")
	seedIdentity(t, db, "user-existing", "returning-subject")

	if got := readUserEmail(t, db, "user-existing"); got.Valid {
		t.Fatalf("email before any login = %+v, want NULL (createTestUser sets none)", got)
	}

	if _, err := login.resolveUser("returning-subject", "fresh@example.org", EmailVerificationUnknown, ""); err != nil {
		t.Fatalf("resolveUser() returned error: %v", err)
	}

	got := readUserEmail(t, db, "user-existing")
	if !got.Valid || got.String != "fresh@example.org" {
		t.Errorf("email after returning oidc login = %+v, want fresh@example.org", got)
	}
}

// TestResolveUserUpdatesEmailWhenProviderClaimChanges is the other normal
// case: a provider that hands a DIFFERENT email than what is already
// stored (the address changed at the identity provider) must overwrite the
// stale one — this is a refresh, not a write-once.
func TestResolveUserUpdatesEmailWhenProviderClaimChanges(t *testing.T) {
	db, _, login := newOIDCLoginWithDefaultRole(t, "gast")
	createTestUser(t, db, "user-existing")
	seedIdentity(t, db, "user-existing", "returning-subject")

	if _, err := login.resolveUser("returning-subject", "old@example.org", EmailVerificationUnknown, ""); err != nil {
		t.Fatalf("first resolveUser() returned error: %v", err)
	}
	if got := readUserEmail(t, db, "user-existing"); !got.Valid || got.String != "old@example.org" {
		t.Fatalf("email after first login = %+v, want old@example.org", got)
	}

	if _, err := login.resolveUser("returning-subject", "new@example.org", EmailVerificationUnknown, ""); err != nil {
		t.Fatalf("second resolveUser() returned error: %v", err)
	}
	if got := readUserEmail(t, db, "user-existing"); !got.Valid || got.String != "new@example.org" {
		t.Errorf("email after second login = %+v, want new@example.org (the provider's changed claim)", got)
	}
}

// TestResolveUserKeepsEmailWhenClaimIsEmptyOnReturningLogin is the CASE
// WHEN guard's whole point, mirrored from forward-auth's own
// TestAuthenticateUpdatesNameAndEmailButKeepsEmailWhenHeaderIsEmpty
// (forwardauth_test.go): a later login that carries NO email claim (a
// provider that stops sending it, or a login requesting a narrower scope)
// must not wipe an address a previous, email-bearing login already stored.
// A naive "always overwrite with the claim" implementation would pass every
// OTHER test in this section and still delete data here — this is the test
// that actually distinguishes "refresh" from "clobber".
func TestResolveUserKeepsEmailWhenClaimIsEmptyOnReturningLogin(t *testing.T) {
	db, _, login := newOIDCLoginWithDefaultRole(t, "gast")
	createTestUser(t, db, "user-existing")
	seedIdentity(t, db, "user-existing", "returning-subject")

	if _, err := login.resolveUser("returning-subject", "keep-me@example.org", EmailVerificationUnknown, ""); err != nil {
		t.Fatalf("first resolveUser() returned error: %v", err)
	}

	if _, err := login.resolveUser("returning-subject", "", EmailVerificationUnknown, ""); err != nil { // no email claim this time
		t.Fatalf("second resolveUser() returned error: %v", err)
	}

	got := readUserEmail(t, db, "user-existing")
	if !got.Valid || got.String != "keep-me@example.org" {
		t.Errorf("email after an empty-claim login = %+v, want keep-me@example.org (kept, not cleared)", got)
	}
}

// TestOIDCLoginCallbackRefreshesEmailThroughFullRoundTrip is the one
// end-to-end check that Callback — not just resolveUser directly — reaches
// updateProfile's email refresh, exercising the same fake-provider round
// trip every other Callback test in this file already uses. completeLogin's
// email argument becomes the fake provider's id token "email" claim
// (issueIDToken), the same claim Callback itself parses and hands to
// resolveUser — this is the one test that proves the whole chain from an
// actual OIDC id token through to the stored row, not just the Go-level
// call resolveUser's own tests exercise directly.
func TestOIDCLoginCallbackRefreshesEmailThroughFullRoundTrip(t *testing.T) {
	provider := newFakeOIDCProvider(t)
	sessions, db := newTestSessionStore(t, time.Hour)
	createTestUser(t, db, "user-existing")
	seedIdentity(t, db, "user-existing", "returning-subject")
	login := newTestOIDCLogin(t, provider, db, sessions)

	authURL := login.BeginLogin("")
	state, code := completeLogin(t, authURL, "returning-subject", "updated@example.org")
	if _, err := login.Callback(context.Background(), state, code, ""); err != nil {
		t.Fatalf("Callback() returned error: %v", err)
	}

	if got := readUserEmail(t, db, "user-existing"); !got.Valid || got.String != "updated@example.org" {
		t.Errorf("email after Callback() = %+v, want updated@example.org", got)
	}
}
