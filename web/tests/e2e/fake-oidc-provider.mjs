#!/usr/bin/env node
// A minimal OpenID Connect discovery endpoint, standing in for a real
// provider so the real Tesserarr server (internal/api.New ->
// identity.NewOIDCLogin -> oidc.NewProvider) can complete the ONE network
// round trip it makes at startup — a discovery document fetch — without a
// real, reachable external IdP.
//
// This deliberately does NOT implement /authorize or /token. None of the
// four views the UI-verification suite checks (/, /login/, /logout/, an
// unknown path) requires completing an actual OIDC login — /login/ only
// needs to render a link to the login-start route, and / redirects an
// unauthenticated visitor to /login/ without ever reaching the provider.
// A server that tried to exercise the real login round trip would need the
// full fake provider internal/identity/oidc_test.go already has (discovery
// + PKCE + signed ID tokens) — reused there, not duplicated here, because
// this file's only job is "let the real server finish booting".
//
// Shape of the discovery document matches internal/identity/oidc_test.go's
// fakeOIDCProvider.handleDiscovery exactly (issuer/authorization_endpoint/
// token_endpoint/jwks_uri plus the supported-values arrays coreos/go-oidc's
// NewProvider expects to find) — that handler is exercised by every OIDC
// round-trip test in this repo's Go suite, so this is a known-good shape,
// not a guess.
import http from "node:http";

const port = Number(process.env.FAKE_OIDC_PORT ?? 9998);
const issuer = `http://localhost:${port}`;

const server = http.createServer((req, res) => {
  if (req.url === "/.well-known/openid-configuration") {
    const body = JSON.stringify({
      issuer,
      authorization_endpoint: `${issuer}/authorize`,
      token_endpoint: `${issuer}/token`,
      jwks_uri: `${issuer}/jwks`,
      response_types_supported: ["code"],
      subject_types_supported: ["public"],
      id_token_signing_alg_values_supported: ["RS256"],
      scopes_supported: ["openid", "profile", "email"],
      token_endpoint_auth_methods_supported: ["client_secret_basic", "client_secret_post"],
      code_challenge_methods_supported: ["S256"],
    });
    res.writeHead(200, { "Content-Type": "application/json" });
    res.end(body);
    return;
  }

  if (req.url === "/jwks") {
    // Empty key set: NewProvider itself never fetches jwks_uri — that only
    // happens lazily inside IDTokenVerifier.Verify(), which none of these
    // checks ever trigger. Served anyway so the URL in the discovery
    // document is at least genuinely reachable, not a dead link.
    res.writeHead(200, { "Content-Type": "application/json" });
    res.end(JSON.stringify({ keys: [] }));
    return;
  }

  // /authorize and /token: deliberately unimplemented, see the file
  // comment above. A 501 is more honest here than a fake success response
  // that would silently pretend a login flow exists when it does not.
  res.writeHead(501, { "Content-Type": "text/plain" });
  res.end("not implemented: this fake provider only serves discovery for boot purposes");
});

server.listen(port, () => {
  console.log(`fake-oidc-provider: listening on ${issuer}`);
});
