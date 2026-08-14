-- Schema version 4: forward-auth adds three columns to users. email is
-- nullable and independent of identities.email (identities.email is
-- per-provider-identity; users.email is the single value the account view
-- shows and lets a user without one enter their own — see
-- docs/superpowers/specs/2026-08-14-tesserarr-forward-auth-design.md,
-- "Name und E-Mail").
--
-- role and role_local are DELIBERATELY two separate columns, not one —
-- see internal/identity/role.go's own comment on ResolveRoleNames for the
-- exact failure this avoids:
--
--   - role_local is the operator's own assignment. Nothing but
--     PromoteUserRole (internal/identity/admin.go, "tesserarr user
--     promote") ever writes it — no login flow, forward-auth or OIDC,
--     touches it, ever, for a new OR an existing user.
--   - role is the CURRENTLY EFFECTIVE role: a comma-joined list of role
--     NAMES, recomputed and overwritten at every entry via
--     ResolveRoleNames(header, roles, defaultRole, role_local) — never
--     read back in as if it were role_local. Rights themselves are looked
--     up fresh against TESSERARR_ROLE_MAP at read time (see
--     internal/identity/role.go, EffectivePermissions), not frozen into
--     this column.
--
-- Both default to '' — "nothing here yet", which ResolveRoleNames already
-- treats as "fall through to the next rule in the precedence chain".
ALTER TABLE users ADD COLUMN email TEXT;
ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN role_local TEXT NOT NULL DEFAULT '';
