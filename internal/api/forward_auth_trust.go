package api

import (
	"net"
	"net/http"
	"net/netip"

	"github.com/strausmann/tesserarr/internal/config"
)

// ForwardAuthSecretHeaderName is the header a trusted guard adds to every
// request it forwards, carrying TESSERARR_FORWARD_AUTH_SHARED_SECRET. Not
// configurable — see the design doc, "Warum das Vertrauen zweistufig ist":
// the operator configures the SAME fixed name as a static header in their
// guard (e.g. Pangolin's resource "headers" field).
const ForwardAuthSecretHeaderName = "X-Tesserarr-Forward-Auth-Secret" //nolint:gosec // G101: this is a HEADER NAME, not a credential value — the string never holds the secret itself, only the well-known key under which the guard is expected to send it

// trustedForwardAuthSource is the FIRST check any forward-auth request
// passes through — see the design doc, "Ablauf" step 2: a request that
// fails this check is rejected without a single identity header ever being
// read. Both conditions are required: the source IP alone is not
// sufficient (a compromised neighbor container shares the guard's network
// segment), and the shared secret alone would still trust an unexpected
// source.
func trustedForwardAuthSource(r *http.Request, cfg *config.Config) bool {
	if !sourceIPTrusted(r.RemoteAddr, cfg.TrustedProxies) {
		return false
	}

	secret := r.Header.Get(ForwardAuthSecretHeaderName)
	if secret == "" {
		return false
	}
	return constantTimeEqual(secret, cfg.ForwardAuthSharedSecret.Reveal())
}

// sourceIPTrusted reports whether remoteAddr's host (r.RemoteAddr's usual
// "host:port" shape) falls inside one of trusted's prefixes. An empty
// trusted list — TESSERARR_TRUSTED_PROXIES unset — trusts nothing, the
// same safe default parsePrefixes already documents in
// internal/config/config.go.
func sourceIPTrusted(remoteAddr string, trusted []netip.Prefix) bool {
	if len(trusted) == 0 {
		return false
	}

	host, _, err := net.SplitHostPort(remoteAddr)
	if err != nil {
		host = remoteAddr
	}
	addr, err := netip.ParseAddr(host)
	if err != nil {
		return false
	}

	for _, prefix := range trusted {
		if prefix.Contains(addr) {
			return true
		}
	}
	return false
}
