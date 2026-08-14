-- Schema version 5: API token expiry. Nullable — NULL means "never
-- expires", the only behavior 0.0.1 had. See internal/apitoken/token.go,
-- Store.Lookup: an expired token must be indistinguishable from a revoked
-- or unknown one (docs/superpowers/specs/2026-08-14-tesserarr-forward-auth-design.md,
-- "API-Token: Ablauf und Verwaltung").
ALTER TABLE api_tokens ADD COLUMN expires_at TEXT;
