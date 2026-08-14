// Package config reads the service configuration from the environment.
//
// Every knob is an environment variable named TESSERARR_<AREA>. Values that
// derive from another value (database path, provisioning directories) fall back
// to a path below DataDir, so a minimal deployment only has to set three
// variables.
package config

import (
	"fmt"
	"net/netip"
	"os"
	"path/filepath"
	"strconv"
	"strings"
)

// minSessionSecretLen is the shortest accepted signing secret. 32 bytes is the
// value the concept mandates; a shorter secret weakens session signatures
// without any warning at runtime, so it is rejected at startup instead.
const minSessionSecretLen = 32

// Config holds the resolved configuration. Fields are populated by Load and are
// read-only afterwards.
type Config struct {
	HTTPAddr          string
	BaseURL           string
	DataDir           string
	DBPath            string
	OutgoingDir       string
	IncomeDir         string
	SessionSecret     Secret
	SessionCookieName string
	TrustedProxies    []netip.Prefix
	LogLevel          string
	// OIDCIssuerURL, OIDCClientID and OIDCClientSecret configure the single
	// generic OpenID Connect provider this deployment authenticates against
	// (see internal/identity.OIDCConfig, which these three feed directly).
	// There is deliberately no separate redirect-URL setting: the address the
	// provider redirects back to is always BaseURL plus a fixed path (see
	// internal/api.LoginOIDCCallbackPath) — the same "derive from BaseURL,
	// don't ask for it twice" choice createInvite already makes for the
	// invite redeem link.
	OIDCIssuerURL    string
	OIDCClientID     string
	OIDCClientSecret Secret
	// ForwardAuthEnabled, ForwardAuthHeaders, ForwardAuthSharedSecret,
	// RoleMap and DefaultRole configure the forward-auth entry path (see
	// internal/api's LoginForwardAuthPath and internal/identity's
	// ForwardAuthLogin). Nothing here is required unless
	// TESSERARR_FORWARD_AUTH_ENABLED is set — see the validation below.
	ForwardAuthEnabled      bool
	ForwardAuthHeaders      ForwardAuthHeaders
	ForwardAuthSharedSecret Secret
	RoleMap                 RoleMap
	DefaultRole             string
}

// Redaction of SessionSecret used to live here as three methods on Config
// (String, GoString, MarshalJSON), copying the struct and substituting a
// placeholder before formatting. A review found the gap that approach could
// not close: cfg.SessionSecret pulled out and printed on its own —
// fmt.Println(cfg.SessionSecret), {{.SessionSecret}} in a template,
// reflection — bypassed all three, because none of them intercept direct
// field access.
//
// SessionSecret is now typed as Secret (see secret.go), which redacts itself
// across the same formatting paths regardless of where it ends up — nested in
// Config, copied into a foreign struct, or read on its own. Config no longer
// needs its own String/GoString/MarshalJSON for this; the field carries its
// own protection. Verification moved with it: see
// internal/config/secret_test.go for the formatting-path coverage, and
// TestConfigRedactsSessionSecretAcrossFormattingPaths in config_test.go for
// confirmation that the protection still holds when the field sits inside
// Config.

// Load reads the configuration from the environment, applies defaults and
// validates required fields. It returns an error describing the first problem
// found rather than starting with a half-valid configuration.
func Load() (*Config, error) {
	cfg := &Config{
		HTTPAddr:          envOr("TESSERARR_HTTP_ADDR", "0.0.0.0:8080"),
		BaseURL:           os.Getenv("TESSERARR_BASE_URL"),
		DataDir:           os.Getenv("TESSERARR_DATA_DIR"),
		SessionSecret:     Secret(os.Getenv("TESSERARR_SESSION_SECRET")),
		SessionCookieName: envOr("TESSERARR_SESSION_COOKIE_NAME", "tesserarr_session"),
		LogLevel:          envOr("TESSERARR_LOG_LEVEL", "info"),
		OIDCIssuerURL:     os.Getenv("TESSERARR_OIDC_ISSUER_URL"),
		OIDCClientID:      os.Getenv("TESSERARR_OIDC_CLIENT_ID"),
		OIDCClientSecret:  Secret(os.Getenv("TESSERARR_OIDC_CLIENT_SECRET")),
	}

	if cfg.BaseURL == "" {
		return nil, fmt.Errorf("TESSERARR_BASE_URL is required")
	}
	if cfg.DataDir == "" {
		return nil, fmt.Errorf("TESSERARR_DATA_DIR is required")
	}
	if len(cfg.SessionSecret) < minSessionSecretLen {
		return nil, fmt.Errorf("TESSERARR_SESSION_SECRET must be at least %d bytes", minSessionSecretLen)
	}
	if cfg.OIDCIssuerURL == "" {
		return nil, fmt.Errorf("TESSERARR_OIDC_ISSUER_URL is required")
	}
	if cfg.OIDCClientID == "" {
		return nil, fmt.Errorf("TESSERARR_OIDC_CLIENT_ID is required")
	}
	if len(cfg.OIDCClientSecret) == 0 {
		return nil, fmt.Errorf("TESSERARR_OIDC_CLIENT_SECRET is required")
	}

	cfg.DBPath = envOr("TESSERARR_DB_PATH", filepath.Join(cfg.DataDir, "tesserarr.db"))
	cfg.OutgoingDir = envOr("TESSERARR_OUTGOING_DIR", filepath.Join(cfg.DataDir, "outgoing"))
	cfg.IncomeDir = envOr("TESSERARR_INCOME_DIR", filepath.Join(cfg.DataDir, "income"))

	proxies, err := parsePrefixes(os.Getenv("TESSERARR_TRUSTED_PROXIES"))
	if err != nil {
		return nil, fmt.Errorf("TESSERARR_TRUSTED_PROXIES: %w", err)
	}
	cfg.TrustedProxies = proxies

	// Forward-auth headers: a named preset (TESSERARR_FORWARD_AUTH_PRESET)
	// merged with explicit per-header overrides, which always win — see
	// resolveForwardAuthHeaders in forwardauth.go. This runs unconditionally,
	// even when forward-auth is disabled, so a later ForwardAuthEnabled=true
	// deploy without a matching header change reuses whatever was already
	// configured, rather than silently defaulting.
	preset := os.Getenv("TESSERARR_FORWARD_AUTH_PRESET")
	overrides := ForwardAuthHeaders{
		Subject:  os.Getenv("TESSERARR_FORWARD_AUTH_HEADER_SUBJECT"),
		Username: os.Getenv("TESSERARR_FORWARD_AUTH_HEADER_USERNAME"),
		Name:     os.Getenv("TESSERARR_FORWARD_AUTH_HEADER_NAME"),
		Email:    os.Getenv("TESSERARR_FORWARD_AUTH_HEADER_EMAIL"),
		Role:     os.Getenv("TESSERARR_FORWARD_AUTH_HEADER_ROLE"),
	}
	headers, err := resolveForwardAuthHeaders(preset, overrides)
	if err != nil {
		return nil, fmt.Errorf("TESSERARR_FORWARD_AUTH_PRESET: %w", err)
	}
	cfg.ForwardAuthHeaders = headers

	enabled, err := envBool("TESSERARR_FORWARD_AUTH_ENABLED")
	if err != nil {
		return nil, err
	}
	cfg.ForwardAuthEnabled = enabled
	cfg.ForwardAuthSharedSecret = Secret(os.Getenv("TESSERARR_FORWARD_AUTH_SHARED_SECRET"))

	// Refuse to start rather than run with a half-configured entry path: an
	// enabled forward-auth without a shared secret would trust ANY request
	// with the right headers (see internal/api's trustedForwardAuthSource,
	// which this configuration feeds), and a subject header that never
	// resolves to anything would silently treat every forward-auth request
	// as anonymous.
	if cfg.ForwardAuthEnabled {
		if len(cfg.ForwardAuthSharedSecret) == 0 {
			return nil, fmt.Errorf("TESSERARR_FORWARD_AUTH_SHARED_SECRET is required when TESSERARR_FORWARD_AUTH_ENABLED is set")
		}
		if cfg.ForwardAuthHeaders.Subject == "" {
			return nil, fmt.Errorf(
				"TESSERARR_FORWARD_AUTH_HEADER_SUBJECT is required: preset %q has no built-in stable identity header",
				preset,
			)
		}
	}

	// TESSERARR_ROLE_MAP and TESSERARR_DEFAULT_ROLE are independent of
	// forward-auth itself (a deployment can define roles for local
	// assignment even without the header-based entry path), but
	// DefaultRole, if set, must name a role the map actually defines —
	// otherwise every forward-auth login that falls back to it (see
	// internal/identity.ResolveRoleNames) would resolve to a role that does
	// not exist.
	roleMap, err := ParseRoleMap(os.Getenv("TESSERARR_ROLE_MAP"))
	if err != nil {
		return nil, fmt.Errorf("TESSERARR_ROLE_MAP: %w", err)
	}
	cfg.RoleMap = roleMap
	cfg.DefaultRole = os.Getenv("TESSERARR_DEFAULT_ROLE")
	if cfg.DefaultRole != "" && cfg.RoleMap != nil {
		if _, ok := cfg.RoleMap[cfg.DefaultRole]; !ok {
			return nil, fmt.Errorf("TESSERARR_DEFAULT_ROLE %q is not a role named in TESSERARR_ROLE_MAP", cfg.DefaultRole)
		}
	}

	return cfg, nil
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

// envBool reads key as an optional boolean flag: unset means false, any
// value strconv.ParseBool cannot parse is a configuration error rather
// than a silently-ignored typo.
func envBool(key string) (bool, error) {
	raw := os.Getenv(key)
	if raw == "" {
		return false, nil
	}
	v, err := strconv.ParseBool(raw)
	if err != nil {
		return false, fmt.Errorf("%s: %q is not a valid boolean", key, raw)
	}
	return v, nil
}

// parsePrefixes turns a comma-separated CIDR list into prefixes. An empty string
// yields no prefixes, which means "trust no proxy" — the safe default.
func parsePrefixes(raw string) ([]netip.Prefix, error) {
	if strings.TrimSpace(raw) == "" {
		return nil, nil
	}
	parts := strings.Split(raw, ",")
	prefixes := make([]netip.Prefix, 0, len(parts))
	for _, part := range parts {
		trimmed := strings.TrimSpace(part)
		if trimmed == "" {
			continue
		}
		prefix, err := netip.ParsePrefix(trimmed)
		if err != nil {
			return nil, fmt.Errorf("%q is not a valid CIDR: %w", trimmed, err)
		}
		prefixes = append(prefixes, prefix)
	}
	return prefixes, nil
}
