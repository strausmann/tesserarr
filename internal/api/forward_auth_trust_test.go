package api

import (
	"net/http"
	"net/http/httptest"
	"net/netip"
	"testing"

	"github.com/strausmann/tesserarr/internal/config"
)

func trustingConfig(secret string) *config.Config {
	return &config.Config{
		TrustedProxies:          []netip.Prefix{netip.MustParsePrefix("172.20.0.0/16")},
		ForwardAuthSharedSecret: config.Secret(secret),
	}
}

func trustedRequest(secret string) *http.Request {
	req := httptest.NewRequest(http.MethodGet, "/", nil)
	req.RemoteAddr = "172.20.0.5:54321"
	if secret != "" {
		req.Header.Set(ForwardAuthSecretHeaderName, secret)
	}
	return req
}

func TestTrustedForwardAuthSourceAcceptsTrustedIPAndCorrectSecret(t *testing.T) {
	cfg := trustingConfig("the-real-secret")
	req := trustedRequest("the-real-secret")

	if !trustedForwardAuthSource(req, cfg) {
		t.Error("trustedForwardAuthSource() = false, want true for a trusted IP with the correct secret")
	}
}

func TestTrustedForwardAuthSourceRejectsMissingSecret(t *testing.T) {
	cfg := trustingConfig("the-real-secret")
	req := trustedRequest("") // no secret header at all

	if trustedForwardAuthSource(req, cfg) {
		t.Error("trustedForwardAuthSource() = true, want false when the secret header is absent")
	}
}

func TestTrustedForwardAuthSourceRejectsWrongSecretEvenWithForgedHeaders(t *testing.T) {
	cfg := trustingConfig("the-real-secret")
	req := trustedRequest("a-guessed-secret")
	req.Header.Set("Remote-User", "admin") // a forged identity header — must not matter at all
	req.Header.Set("Remote-Role", "admin")

	if trustedForwardAuthSource(req, cfg) {
		t.Error("trustedForwardAuthSource() = true, want false for the wrong secret, regardless of other headers")
	}
}

func TestTrustedForwardAuthSourceRejectsUntrustedIPEvenWithCorrectSecret(t *testing.T) {
	cfg := trustingConfig("the-real-secret")
	req := trustedRequest("the-real-secret")
	req.RemoteAddr = "10.0.0.9:1234" // outside 172.20.0.0/16

	if trustedForwardAuthSource(req, cfg) {
		t.Error("trustedForwardAuthSource() = true, want false for an untrusted source IP")
	}
}

func TestTrustedForwardAuthSourceRejectsEverythingWhenNoProxiesConfigured(t *testing.T) {
	cfg := &config.Config{ForwardAuthSharedSecret: config.Secret("the-real-secret")} // TrustedProxies is nil
	req := trustedRequest("the-real-secret")

	if trustedForwardAuthSource(req, cfg) {
		t.Error("trustedForwardAuthSource() = true, want false when TrustedProxies is empty — the safe default")
	}
}

func TestTrustedForwardAuthSourceRejectsMalformedRemoteAddr(t *testing.T) {
	cfg := trustingConfig("the-real-secret")
	req := trustedRequest("the-real-secret")
	req.RemoteAddr = "not-an-address"

	if trustedForwardAuthSource(req, cfg) {
		t.Error("trustedForwardAuthSource() = true, want false for a RemoteAddr that does not parse")
	}
}
