package config

import (
	"bytes"
	"encoding/json"
	"fmt"
	"log/slog"
	"strings"
	"testing"
)

func TestLoadAppliesDefaults(t *testing.T) {
	t.Setenv("TESSERARR_BASE_URL", "https://tesserarr.example.org")
	t.Setenv("TESSERARR_DATA_DIR", "/data")
	t.Setenv("TESSERARR_SESSION_SECRET", "0123456789abcdef0123456789abcdef")
	t.Setenv("TESSERARR_OIDC_ISSUER_URL", "https://idp.example.org/application/o/tesserarr/")
	t.Setenv("TESSERARR_OIDC_CLIENT_ID", "tesserarr-client")
	t.Setenv("TESSERARR_OIDC_CLIENT_SECRET", "oidc-client-secret")

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
	if cfg.OIDCIssuerURL != "https://idp.example.org/application/o/tesserarr/" {
		t.Errorf("OIDCIssuerURL = %q, want %q", cfg.OIDCIssuerURL, "https://idp.example.org/application/o/tesserarr/")
	}
	if cfg.OIDCClientID != "tesserarr-client" {
		t.Errorf("OIDCClientID = %q, want %q", cfg.OIDCClientID, "tesserarr-client")
	}
	if cfg.OIDCClientSecret.Reveal() != "oidc-client-secret" {
		t.Errorf("OIDCClientSecret.Reveal() = %q, want %q", cfg.OIDCClientSecret.Reveal(), "oidc-client-secret")
	}
}

func TestLoadAppliesOverrides(t *testing.T) {
	t.Setenv("TESSERARR_BASE_URL", "https://tesserarr.example.org")
	t.Setenv("TESSERARR_DATA_DIR", "/data")
	t.Setenv("TESSERARR_SESSION_SECRET", "0123456789abcdef0123456789abcdef")
	t.Setenv("TESSERARR_OIDC_ISSUER_URL", "https://idp.example.org/application/o/tesserarr/")
	t.Setenv("TESSERARR_OIDC_CLIENT_ID", "tesserarr-client")
	t.Setenv("TESSERARR_OIDC_CLIENT_SECRET", "oidc-client-secret")
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
	t.Setenv("TESSERARR_OIDC_ISSUER_URL", "https://idp.example.org/application/o/tesserarr/")
	t.Setenv("TESSERARR_OIDC_CLIENT_ID", "tesserarr-client")
	t.Setenv("TESSERARR_OIDC_CLIENT_SECRET", "oidc-client-secret")
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
	t.Setenv("TESSERARR_OIDC_ISSUER_URL", "https://idp.example.org/application/o/tesserarr/")
	t.Setenv("TESSERARR_OIDC_CLIENT_ID", "tesserarr-client")
	t.Setenv("TESSERARR_OIDC_CLIENT_SECRET", "oidc-client-secret")
	t.Setenv("TESSERARR_TRUSTED_PROXIES", "not-a-cidr")

	if _, err := Load(); err == nil {
		t.Fatal("Load() returned no error, want error about malformed CIDR")
	}
}

func TestLoadRejectsMissingOIDCIssuerURL(t *testing.T) {
	t.Setenv("TESSERARR_BASE_URL", "https://tesserarr.example.org")
	t.Setenv("TESSERARR_DATA_DIR", "/data")
	t.Setenv("TESSERARR_SESSION_SECRET", "0123456789abcdef0123456789abcdef")
	// Deliberately unset: TESSERARR_OIDC_ISSUER_URL
	t.Setenv("TESSERARR_OIDC_CLIENT_ID", "tesserarr-client")
	t.Setenv("TESSERARR_OIDC_CLIENT_SECRET", "oidc-client-secret")

	if _, err := Load(); err == nil {
		t.Fatal("Load() returned no error, want error about missing TESSERARR_OIDC_ISSUER_URL")
	}
}

func TestLoadRejectsMissingOIDCClientID(t *testing.T) {
	t.Setenv("TESSERARR_BASE_URL", "https://tesserarr.example.org")
	t.Setenv("TESSERARR_DATA_DIR", "/data")
	t.Setenv("TESSERARR_SESSION_SECRET", "0123456789abcdef0123456789abcdef")
	t.Setenv("TESSERARR_OIDC_ISSUER_URL", "https://idp.example.org/application/o/tesserarr/")
	// Deliberately unset: TESSERARR_OIDC_CLIENT_ID
	t.Setenv("TESSERARR_OIDC_CLIENT_SECRET", "oidc-client-secret")

	if _, err := Load(); err == nil {
		t.Fatal("Load() returned no error, want error about missing TESSERARR_OIDC_CLIENT_ID")
	}
}

func TestLoadRejectsMissingOIDCClientSecret(t *testing.T) {
	t.Setenv("TESSERARR_BASE_URL", "https://tesserarr.example.org")
	t.Setenv("TESSERARR_DATA_DIR", "/data")
	t.Setenv("TESSERARR_SESSION_SECRET", "0123456789abcdef0123456789abcdef")
	t.Setenv("TESSERARR_OIDC_ISSUER_URL", "https://idp.example.org/application/o/tesserarr/")
	t.Setenv("TESSERARR_OIDC_CLIENT_ID", "tesserarr-client")
	// Deliberately unset: TESSERARR_OIDC_CLIENT_SECRET

	if _, err := Load(); err == nil {
		t.Fatal("Load() returned no error, want error about missing TESSERARR_OIDC_CLIENT_SECRET")
	}
}

// setForwardAuthBaseEnv sets the same required-variable baseline
// TestLoadAppliesDefaults uses, so each forward-auth test only has to set
// the forward-auth-specific variables it actually cares about.
func setForwardAuthBaseEnv(t *testing.T) {
	t.Helper()
	t.Setenv("TESSERARR_BASE_URL", "https://tesserarr.example.org")
	t.Setenv("TESSERARR_DATA_DIR", "/data")
	t.Setenv("TESSERARR_SESSION_SECRET", "0123456789abcdef0123456789abcdef")
	t.Setenv("TESSERARR_OIDC_ISSUER_URL", "https://idp.example.org/application/o/tesserarr/")
	t.Setenv("TESSERARR_OIDC_CLIENT_ID", "tesserarr-client")
	t.Setenv("TESSERARR_OIDC_CLIENT_SECRET", "oidc-client-secret")
}

func TestLoadStillWorksWithNoForwardAuthVariablesSet(t *testing.T) {
	setForwardAuthBaseEnv(t)

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load() returned error: %v", err)
	}
	if cfg.ForwardAuthEnabled {
		t.Error("ForwardAuthEnabled = true, want false (nothing set)")
	}
}

func TestLoadRefusesToStartWhenForwardAuthEnabledWithoutSharedSecret(t *testing.T) {
	setForwardAuthBaseEnv(t)
	t.Setenv("TESSERARR_FORWARD_AUTH_ENABLED", "true")
	t.Setenv("TESSERARR_FORWARD_AUTH_PRESET", "pangolin")

	if _, err := Load(); err == nil {
		t.Fatal("Load() with forward-auth enabled and no shared secret returned no error")
	}
}

func TestLoadRefusesToStartWhenPresetHasNoSubjectHeaderAndNoneIsSet(t *testing.T) {
	setForwardAuthBaseEnv(t)
	t.Setenv("TESSERARR_FORWARD_AUTH_ENABLED", "true")
	t.Setenv("TESSERARR_FORWARD_AUTH_PRESET", "authelia")
	t.Setenv("TESSERARR_FORWARD_AUTH_SHARED_SECRET", "shared-secret-value")

	if _, err := Load(); err == nil {
		t.Fatal("Load() with the authelia preset and no subject header override returned no error")
	}
}

func TestLoadAcceptsAutheliaPresetWithExplicitSubjectHeader(t *testing.T) {
	setForwardAuthBaseEnv(t)
	t.Setenv("TESSERARR_FORWARD_AUTH_ENABLED", "true")
	t.Setenv("TESSERARR_FORWARD_AUTH_PRESET", "authelia")
	t.Setenv("TESSERARR_FORWARD_AUTH_HEADER_SUBJECT", "Remote-Email")
	t.Setenv("TESSERARR_FORWARD_AUTH_SHARED_SECRET", "shared-secret-value")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load() returned error: %v", err)
	}
	if cfg.ForwardAuthHeaders.Subject != "Remote-Email" {
		t.Errorf("Subject = %q, want Remote-Email", cfg.ForwardAuthHeaders.Subject)
	}
}

func TestLoadRejectsMalformedRoleMap(t *testing.T) {
	setForwardAuthBaseEnv(t)
	t.Setenv("TESSERARR_ROLE_MAP", "not-a-valid-entry")

	if _, err := Load(); err == nil {
		t.Fatal("Load() with a malformed TESSERARR_ROLE_MAP returned no error")
	}
}

func TestLoadRejectsDefaultRoleNotInRoleMap(t *testing.T) {
	setForwardAuthBaseEnv(t)
	t.Setenv("TESSERARR_ROLE_MAP", "familie:request")
	t.Setenv("TESSERARR_DEFAULT_ROLE", "admin")

	if _, err := Load(); err == nil {
		t.Fatal("Load() with a TESSERARR_DEFAULT_ROLE absent from TESSERARR_ROLE_MAP returned no error")
	}
}

func TestLoadAcceptsMatchingDefaultRole(t *testing.T) {
	setForwardAuthBaseEnv(t)
	t.Setenv("TESSERARR_ROLE_MAP", "familie:request")
	t.Setenv("TESSERARR_DEFAULT_ROLE", "familie")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load() returned error: %v", err)
	}
	if cfg.DefaultRole != "familie" {
		t.Errorf("DefaultRole = %q, want familie", cfg.DefaultRole)
	}
}

// marker is a value distinctive enough that its presence in formatted output
// cannot be mistaken for anything else the struct might print.
const sessionSecretMarker = "sess-secret-marker-9f3a1b7c0d2e4f68"

// TestConfigRedactsSessionSecretAcrossFormattingPaths covers every formatting
// path a later command could plausibly reach for when it logs or dumps a
// Config: fmt verbs (%v, %+v, %#v), encoding/json, and both stdlib slog
// handlers — each with a value and a pointer. Four of these paths (%#v,
// json.Marshal, slog.JSONHandler — value and pointer each) leaked the secret
// before String()/GoString()/MarshalJSON() existed; %v, %+v and
// slog.TextHandler were already clean via String(). All of them are asserted
// here together so a later refactor cannot silently reopen one.
//
// %q on the whole struct used to be in this list too, and was just as clean
// (same per-field Stringer recursion %v uses). It was dropped when the
// forward-auth fields landed: Config now carries a plain bool
// (ForwardAuthEnabled) and a map (RoleMap), and `go vet`'s printf check
// correctly refuses %q on a struct with fields it cannot itself quote —
// regardless of whether the one field that matters here (SessionSecret)
// redacts fine. %v/%+v exercise the identical recursion and stay in the
// list, so this loses no coverage of the property this test actually checks.
func TestConfigRedactsSessionSecretAcrossFormattingPaths(t *testing.T) {
	newCfg := func() Config {
		return Config{
			BaseURL:       "https://tesserarr.example.org",
			SessionSecret: sessionSecretMarker,
		}
	}

	slogOutput := func(handler func(w *bytes.Buffer) *slog.Logger, arg any) string {
		var buf bytes.Buffer
		handler(&buf).Info("test", "config", arg)
		return buf.String()
	}
	textHandler := func(w *bytes.Buffer) *slog.Logger { return slog.New(slog.NewTextHandler(w, nil)) }
	jsonHandler := func(w *bytes.Buffer) *slog.Logger { return slog.New(slog.NewJSONHandler(w, nil)) }

	cases := []struct {
		name string
		out  func() string
	}{
		{"%v value", func() string { return fmt.Sprintf("%v", newCfg()) }},
		{"%v pointer", func() string { c := newCfg(); return fmt.Sprintf("%v", &c) }},
		{"%+v value", func() string { return fmt.Sprintf("%+v", newCfg()) }},
		{"%+v pointer", func() string { c := newCfg(); return fmt.Sprintf("%+v", &c) }},
		{"%#v value", func() string { return fmt.Sprintf("%#v", newCfg()) }},
		{"%#v pointer", func() string { c := newCfg(); return fmt.Sprintf("%#v", &c) }},
		{"json.Marshal value", func() string {
			b, err := json.Marshal(newCfg()) //nolint:gosec // G117: this test asserts that marshaling THIS field redacts it — flagging the marshal call names the method, not a defect
			if err != nil {
				t.Fatalf("json.Marshal(value) returned error: %v", err)
			}
			return string(b)
		}},
		{"json.Marshal pointer", func() string {
			c := newCfg()
			b, err := json.Marshal(&c) //nolint:gosec // G117: same as above, pointer variant
			if err != nil {
				t.Fatalf("json.Marshal(pointer) returned error: %v", err)
			}
			return string(b)
		}},
		{"slog.TextHandler value", func() string { return slogOutput(textHandler, newCfg()) }},
		{"slog.TextHandler pointer", func() string { c := newCfg(); return slogOutput(textHandler, &c) }},
		{"slog.JSONHandler value", func() string { return slogOutput(jsonHandler, newCfg()) }},
		{"slog.JSONHandler pointer", func() string { c := newCfg(); return slogOutput(jsonHandler, &c) }},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			out := tc.out()
			if strings.Contains(out, sessionSecretMarker) {
				t.Errorf("formatted output leaks SessionSecret: %s", out)
			}
		})
	}
}
