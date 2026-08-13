package config

import (
	"fmt"
	"strings"
	"testing"
)

func TestLoadAppliesDefaults(t *testing.T) {
	t.Setenv("TESSERARR_BASE_URL", "https://tesserarr.example.org")
	t.Setenv("TESSERARR_DATA_DIR", "/data")
	t.Setenv("TESSERARR_SESSION_SECRET", "0123456789abcdef0123456789abcdef")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load() returned error: %v", err)
	}

	if cfg.HTTPAddr != "0.0.0.0:8080" {
		t.Errorf("HTTPAddr = %q, want %q", cfg.HTTPAddr, "0.0.0.0:8080")
	}
	if cfg.DBPath != "/data/tesserarr.db" {
		t.Errorf("DBPath = %q, want %q", cfg.DBPath, "/data/tesserarr.db")
	}
	if cfg.OutgoingDir != "/data/outgoing" {
		t.Errorf("OutgoingDir = %q, want %q", cfg.OutgoingDir, "/data/outgoing")
	}
	if cfg.SessionCookieName != "tesserarr_session" {
		t.Errorf("SessionCookieName = %q, want %q", cfg.SessionCookieName, "tesserarr_session")
	}
	if cfg.LogLevel != "info" {
		t.Errorf("LogLevel = %q, want %q", cfg.LogLevel, "info")
	}
	if len(cfg.TrustedProxies) != 0 {
		t.Errorf("TrustedProxies = %v, want empty", cfg.TrustedProxies)
	}
}

func TestLoadAppliesOverrides(t *testing.T) {
	t.Setenv("TESSERARR_BASE_URL", "https://tesserarr.example.org")
	t.Setenv("TESSERARR_DATA_DIR", "/data")
	t.Setenv("TESSERARR_SESSION_SECRET", "0123456789abcdef0123456789abcdef")
	t.Setenv("TESSERARR_HTTP_ADDR", "127.0.0.1:9090")
	t.Setenv("TESSERARR_DB_PATH", "/custom/db.sqlite")
	t.Setenv("TESSERARR_OUTGOING_DIR", "/custom/outgoing")
	t.Setenv("TESSERARR_INCOME_DIR", "/custom/income")
	t.Setenv("TESSERARR_SESSION_COOKIE_NAME", "custom_session")
	t.Setenv("TESSERARR_LOG_LEVEL", "debug")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load() returned error: %v", err)
	}

	if cfg.HTTPAddr != "127.0.0.1:9090" {
		t.Errorf("HTTPAddr = %q, want %q", cfg.HTTPAddr, "127.0.0.1:9090")
	}
	if cfg.DBPath != "/custom/db.sqlite" {
		t.Errorf("DBPath = %q, want %q", cfg.DBPath, "/custom/db.sqlite")
	}
	if cfg.OutgoingDir != "/custom/outgoing" {
		t.Errorf("OutgoingDir = %q, want %q", cfg.OutgoingDir, "/custom/outgoing")
	}
	if cfg.IncomeDir != "/custom/income" {
		t.Errorf("IncomeDir = %q, want %q", cfg.IncomeDir, "/custom/income")
	}
	if cfg.SessionCookieName != "custom_session" {
		t.Errorf("SessionCookieName = %q, want %q", cfg.SessionCookieName, "custom_session")
	}
	if cfg.LogLevel != "debug" {
		t.Errorf("LogLevel = %q, want %q", cfg.LogLevel, "debug")
	}
}

func TestLoadRejectsMissingRequired(t *testing.T) {
	// Deliberately unset: TESSERARR_BASE_URL
	t.Setenv("TESSERARR_DATA_DIR", "/data")
	t.Setenv("TESSERARR_SESSION_SECRET", "0123456789abcdef0123456789abcdef")

	if _, err := Load(); err == nil {
		t.Fatal("Load() returned no error, want error about missing TESSERARR_BASE_URL")
	}
}

func TestLoadRejectsShortSessionSecret(t *testing.T) {
	t.Setenv("TESSERARR_BASE_URL", "https://tesserarr.example.org")
	t.Setenv("TESSERARR_DATA_DIR", "/data")
	t.Setenv("TESSERARR_SESSION_SECRET", "too-short")

	if _, err := Load(); err == nil {
		t.Fatal("Load() returned no error, want error about session secret length")
	}
}

func TestLoadParsesTrustedProxies(t *testing.T) {
	t.Setenv("TESSERARR_BASE_URL", "https://tesserarr.example.org")
	t.Setenv("TESSERARR_DATA_DIR", "/data")
	t.Setenv("TESSERARR_SESSION_SECRET", "0123456789abcdef0123456789abcdef")
	t.Setenv("TESSERARR_TRUSTED_PROXIES", "192.0.2.0/24, 198.51.100.0/24")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load() returned error: %v", err)
	}
	if len(cfg.TrustedProxies) != 2 {
		t.Fatalf("TrustedProxies has %d entries, want 2", len(cfg.TrustedProxies))
	}
	if cfg.TrustedProxies[0].String() != "192.0.2.0/24" {
		t.Errorf("TrustedProxies[0] = %q, want %q", cfg.TrustedProxies[0].String(), "192.0.2.0/24")
	}
}

func TestLoadRejectsMalformedTrustedProxies(t *testing.T) {
	t.Setenv("TESSERARR_BASE_URL", "https://tesserarr.example.org")
	t.Setenv("TESSERARR_DATA_DIR", "/data")
	t.Setenv("TESSERARR_SESSION_SECRET", "0123456789abcdef0123456789abcdef")
	t.Setenv("TESSERARR_TRUSTED_PROXIES", "not-a-cidr")

	if _, err := Load(); err == nil {
		t.Fatal("Load() returned no error, want error about malformed CIDR")
	}
}

// marker is a value distinctive enough that its presence in formatted output
// cannot be mistaken for anything else the struct might print.
const sessionSecretMarker = "sess-secret-marker-9f3a1b7c0d2e4f68"

func TestConfigFormattingRedactsSessionSecret(t *testing.T) {
	cfg := Config{
		BaseURL:       "https://tesserarr.example.org",
		SessionSecret: sessionSecretMarker,
	}

	cases := []struct {
		name string
		out  string
	}{
		{"value %v", fmt.Sprintf("%v", cfg)},
		{"value %+v", fmt.Sprintf("%+v", cfg)},
		{"pointer %v", fmt.Sprintf("%v", &cfg)},
		{"pointer %+v", fmt.Sprintf("%+v", &cfg)},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if strings.Contains(tc.out, sessionSecretMarker) {
				t.Errorf("formatted output leaks SessionSecret: %s", tc.out)
			}
		})
	}
}
