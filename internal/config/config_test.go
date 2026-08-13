package config

import (
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
