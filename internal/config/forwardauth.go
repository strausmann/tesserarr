package config

import "fmt"

// ForwardAuthHeaders names the incoming HTTP headers the forward-auth entry
// path reads to learn who a request claims to be.
type ForwardAuthHeaders struct {
	Subject  string
	Username string
	Name     string
	Email    string
	Role     string
}

// forwardAuthPresets are the default header names per known guard (see
// docs/research/2026-08-14-forward-auth-portale.md for the sourcing).
// Subject is deliberately empty for authelia and oauth2-proxy: neither
// sends a header that survives a rename in the guard — Load refuses to
// start with forward-auth enabled unless the operator names a subject
// header explicitly for those two.
var forwardAuthPresets = map[string]ForwardAuthHeaders{
	"pangolin": {
		Subject:  "Remote-User-Id",
		Username: "Remote-User",
		Name:     "Remote-Name",
		Email:    "Remote-Email",
		Role:     "Remote-Role",
	},
	"authentik": {
		Subject:  "X-authentik-uid",
		Username: "X-authentik-username",
		Name:     "X-authentik-name",
		Email:    "X-authentik-email",
	},
	"tinyauth": {
		Subject:  "Remote-Sub",
		Username: "Remote-User",
		Name:     "Remote-Name",
		Email:    "Remote-Email",
	},
	"authelia": {
		Username: "Remote-User",
		Name:     "Remote-Name",
		Email:    "Remote-Email",
	},
	"oauth2-proxy": {
		Username: "X-Auth-Request-Preferred-Username",
		Email:    "X-Auth-Request-Email",
	},
}

// resolveForwardAuthHeaders merges the named preset (if any) with explicit
// TESSERARR_FORWARD_AUTH_HEADER_* overrides, which always win field by
// field — an operator overriding one header name does not have to repeat
// the rest. An empty preset with no overrides yields an all-empty
// ForwardAuthHeaders, which Load's own validation then rejects when
// forward-auth is enabled (see the Subject check there).
func resolveForwardAuthHeaders(preset string, overrides ForwardAuthHeaders) (ForwardAuthHeaders, error) {
	headers := ForwardAuthHeaders{}
	if preset != "" {
		p, ok := forwardAuthPresets[preset]
		if !ok {
			return ForwardAuthHeaders{}, fmt.Errorf("unknown preset %q", preset)
		}
		headers = p
	}

	if overrides.Subject != "" {
		headers.Subject = overrides.Subject
	}
	if overrides.Username != "" {
		headers.Username = overrides.Username
	}
	if overrides.Name != "" {
		headers.Name = overrides.Name
	}
	if overrides.Email != "" {
		headers.Email = overrides.Email
	}
	if overrides.Role != "" {
		headers.Role = overrides.Role
	}
	return headers, nil
}
