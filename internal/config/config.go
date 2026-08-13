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
