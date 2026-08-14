package api

import (
	"crypto"
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"math/big"
	"net/http"
	"net/http/httptest"
	"net/url"
	"sync"
	"testing"
	"time"
)

// fakeOIDCClientID is the client id every test in this package configures
// OIDCLogin with — shared so the fake provider's issued ID tokens (aud
// claim, see issueFakeIDToken) always match what newTestAPI's cfg and any
// test-specific OIDCConfig actually verify against.
const fakeOIDCClientID = "tesserarr-test-client"

// fakeOIDCProvider is a minimal, self-contained OpenID Connect provider used
// only by this package's tests — discovery, PKCE-protected authorization,
// single-use codes, an RS256-signed ID token, a JWKS endpoint. It is
// deliberately a smaller copy of internal/identity/oidc_test.go's provider
// of the same name: that file already covers every error path OIDCLogin
// itself can hit (missing id_token, wrong audience, unparseable claims,
// ...), so this one only needs to carry a login far enough for the HTTP
// wiring under test here — the routes in login.go, not the identity package
// underneath them — to see a real cookie come out the other end. See the
// SDD task report for why: "Nutz den Fake-Anbieter aus
// internal/identity/oidc_test.go als Vorlage, falls du einen brauchst."
type fakeOIDCProvider struct {
	server *httptest.Server
	key    *rsa.PrivateKey
	kid    string

	mu    sync.Mutex
	codes map[string]*fakeAuthCode
}

type fakeAuthCode struct {
	subject             string
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
	writeFakeJSON(w, map[string]any{
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

// handleAuthorize instantly "approves" the request, exactly as
// identity/oidc_test.go's provider does and for the same reason: there is
// no real user interface to drive in a test. Which subject the code is for
// comes from the test_subject query parameter a test adds on top of
// whatever BeginLogin produced (see completeFakeLogin below) — a real
// provider has no such parameter.
func (p *fakeOIDCProvider) handleAuthorize(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()

	subject := q.Get("test_subject")
	if subject == "" {
		subject = "default-test-subject"
	}

	code := randFakeHex(16)
	p.mu.Lock()
	p.codes[code] = &fakeAuthCode{
		subject:             subject,
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

	// redirect_uri is this handler's own incoming request's value, sent by
	// OIDCLogin itself (see New in router.go, which builds it from
	// cfg.BaseURL and LoginOIDCCallbackPath) — not attacker input.
	http.Redirect(w, r, redirect.String(), http.StatusFound) //nolint:gosec // G710: test-only fake provider, redirect_uri is our own request's own configured RedirectURL, not attacker input
}

// handleToken is the one place PKCE is enforced and a code is consumed.
// Client authentication is deliberately not checked — see the equivalent
// comment on identity/oidc_test.go's handleToken.
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
	if auth.codeChallengeMethod != "S256" || !fakePKCEMatches(auth.codeChallenge, verifier) {
		http.Error(w, `{"error":"invalid_grant","error_description":"pkce verification failed"}`, http.StatusBadRequest)
		return
	}

	writeFakeJSON(w, map[string]any{
		"access_token": "fake-access-token",
		"token_type":   "Bearer",
		"expires_in":   3600,
		"id_token":     p.issueFakeIDToken(auth.subject),
	})
}

func fakePKCEMatches(challenge, verifier string) bool {
	sum := sha256.Sum256([]byte(verifier))
	return base64.RawURLEncoding.EncodeToString(sum[:]) == challenge
}

func (p *fakeOIDCProvider) handleJWKS(w http.ResponseWriter, _ *http.Request) {
	pub := &p.key.PublicKey
	writeFakeJSON(w, map[string]any{
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

// issueFakeIDToken hand-builds and RS256-signs a minimal ID token — see
// identity/oidc_test.go's issueIDToken for why no JOSE library is used.
func (p *fakeOIDCProvider) issueFakeIDToken(subject string) string {
	now := time.Now().UTC()
	header := map[string]any{"alg": "RS256", "typ": "JWT", "kid": p.kid}
	claims := map[string]any{
		"iss": p.server.URL,
		"sub": subject,
		"aud": fakeOIDCClientID,
		"iat": now.Unix(),
		"exp": now.Add(10 * time.Minute).Unix(),
	}

	signingInput := mustFakeB64JSON(header) + "." + mustFakeB64JSON(claims)
	sum := sha256.Sum256([]byte(signingInput))
	sig, err := rsa.SignPKCS1v15(rand.Reader, p.key, crypto.SHA256, sum[:])
	if err != nil {
		panic("fake oidc provider: sign id token: " + err.Error())
	}
	return signingInput + "." + base64.RawURLEncoding.EncodeToString(sig)
}

func mustFakeB64JSON(v any) string {
	b, err := json.Marshal(v)
	if err != nil {
		panic("fake oidc provider: marshal claims: " + err.Error())
	}
	return base64.RawURLEncoding.EncodeToString(b)
}

func writeFakeJSON(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(v)
}

func randFakeHex(n int) string {
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

// completeFakeLogin drives one full authorization round trip against
// authURL (as identity.OIDCLogin.BeginLogin returns it) for subject,
// exactly as a browser would after a human actually logged in at the
// provider. It returns the state and code the provider's redirect carried
// back — what a real browser hands to LoginOIDCCallbackPath as query
// parameters.
func completeFakeLogin(t *testing.T, authURL, subject string) (state, code string) {
	t.Helper()

	u, err := url.Parse(authURL)
	if err != nil {
		t.Fatalf("parse auth URL %q: %v", authURL, err)
	}
	if subject != "" {
		q := u.Query()
		q.Set("test_subject", subject)
		u.RawQuery = q.Encode()
	}

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
