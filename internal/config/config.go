// Package config reads the service configuration from the environment.
//
// Every knob is an environment variable named TESSERARR_<AREA>. Values that
// derive from another value (database path, provisioning directories) fall back
// to a path below DataDir, so a minimal deployment only has to set three
// variables.
package config

import (
	"encoding/json"
	"fmt"
	"net/netip"
	"os"
	"path/filepath"
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
	SessionSecret     string
	SessionCookieName string
	TrustedProxies    []netip.Prefix
	LogLevel          string
}

// Redaction of SessionSecret when Config as a whole is formatted or
// serialized is spread across three methods below, because no single Go
// interface covers every such path a caller can reach for. Each method's doc
// comment states only the paths that were actually run against a build
// without that method and seen to leak the marker value in
// internal/config/config_test.go (TestConfigRedactsSessionSecretAcrossFormattingPaths)
// — not paths assumed to behave the same way.
//
// This does NOT cover direct access to the field itself — cfg.SessionSecret
// printed on its own, reflected into a template, or read by any other means
// that bypasses formatting/serializing the struct — no method on Config can
// intercept that.
//
// All three follow the same pattern: copy Config, replace SessionSecret with
// "REDACTED", then format the copy through a local configAlias type (same
// fields, none of these three methods) so formatting does not recurse back
// into the method itself.

// String implements fmt.Stringer. A value receiver is deliberate: Go
// promotes value-receiver methods into the pointer's method set too, so both
// Config and *Config satisfy fmt.Stringer.
//
// Verified redacted through String(): %v and %+v (Stringer is honored by
// both — the '+' flag only changes how the *default* struct format prints
// field names, it does not bypass a Stringer), %q (which formats via String()
// and then quotes it), and slog.TextHandler (whose fallback for a value
// without LogValue was checked to go through the same Stringer path). All
// four for both value and pointer.
//
// NOT covered by String(): %#v and any encoding/json-based path, including
// slog.JSONHandler — see GoString and MarshalJSON below.
func (c Config) String() string {
	redacted := c
	if redacted.SessionSecret != "" {
		redacted.SessionSecret = "REDACTED"
	}
	type configAlias Config
	return fmt.Sprintf("%+v", configAlias(redacted))
}

// GoString implements fmt.GoStringer for %#v. Unlike %v/%+v/%q, %#v does not
// consult fmt.Stringer at all — it always falls back to a Go-syntax struct
// literal with the real field values unless GoStringer is implemented, which
// is why String() alone left %#v leaking SessionSecret in full for both
// value and pointer (confirmed by running %#v against a build without this
// method before adding it).
func (c Config) GoString() string {
	redacted := c
	if redacted.SessionSecret != "" {
		redacted.SessionSecret = "REDACTED"
	}
	type configAlias Config
	return fmt.Sprintf("%#v", configAlias(redacted))
}

// MarshalJSON implements json.Marshaler.
//
// Verified redacted through MarshalJSON(): json.Marshal directly (value and
// pointer), and slog.JSONHandler (value and pointer) — checked with a
// standalone probe that gave a type only a MarshalJSON method, no LogValue,
// and confirmed slog.JSONHandler's output for that type went through
// MarshalJSON rather than reflecting the raw struct. A separate
// slog.LogValuer is therefore not implemented: it would be redundant with
// this method for both handlers this codebase's dependencies ship
// (TextHandler via String(), JSONHandler via MarshalJSON). A future
// slog.Handler that reaches Kind Any values through some other path than
// fmt or encoding/json would not be covered by either — that gap is not
// closed, only the two stdlib handlers actually exercised are.
func (c Config) MarshalJSON() ([]byte, error) {
	redacted := c
	if redacted.SessionSecret != "" {
		redacted.SessionSecret = "REDACTED"
	}
	type configAlias Config
	return json.Marshal(configAlias(redacted))
}

// Load reads the configuration from the environment, applies defaults and
// validates required fields. It returns an error describing the first problem
// found rather than starting with a half-valid configuration.
func Load() (*Config, error) {
	cfg := &Config{
		HTTPAddr:          envOr("TESSERARR_HTTP_ADDR", "0.0.0.0:8080"),
		BaseURL:           os.Getenv("TESSERARR_BASE_URL"),
		DataDir:           os.Getenv("TESSERARR_DATA_DIR"),
		SessionSecret:     os.Getenv("TESSERARR_SESSION_SECRET"),
		SessionCookieName: envOr("TESSERARR_SESSION_COOKIE_NAME", "tesserarr_session"),
		LogLevel:          envOr("TESSERARR_LOG_LEVEL", "info"),
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

	cfg.DBPath = envOr("TESSERARR_DB_PATH", filepath.Join(cfg.DataDir, "tesserarr.db"))
	cfg.OutgoingDir = envOr("TESSERARR_OUTGOING_DIR", filepath.Join(cfg.DataDir, "outgoing"))
	cfg.IncomeDir = envOr("TESSERARR_INCOME_DIR", filepath.Join(cfg.DataDir, "income"))

	proxies, err := parsePrefixes(os.Getenv("TESSERARR_TRUSTED_PROXIES"))
	if err != nil {
		return nil, fmt.Errorf("TESSERARR_TRUSTED_PROXIES: %w", err)
	}
	cfg.TrustedProxies = proxies

	return cfg, nil
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
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
