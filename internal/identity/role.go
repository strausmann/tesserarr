package identity

import (
	"strings"

	"github.com/strausmann/tesserarr/internal/config"
)

// SplitRoleHeaderValues splits a forward-auth role header's raw value into
// individual role names. Both comma and whitespace count as separators —
// how a given guard joins more than one role is unconfirmed (see
// docs/superpowers/specs/2026-08-14-tesserarr-forward-auth-design.md,
// "Die Kopfzeile trägt eine Liste, nicht einen Wert") — and empty parts and
// surrounding whitespace are dropped.
func SplitRoleHeaderValues(raw string) []string {
	values := strings.FieldsFunc(raw, func(r rune) bool {
		return r == ',' || r == ' ' || r == '\t'
	})
	out := make([]string, 0, len(values))
	for _, v := range values {
		v = strings.TrimSpace(v)
		if v != "" {
			out = append(out, v)
		}
	}
	return out
}

// matchKnownRoles returns the subset of values that name a role in roles,
// in the order they appeared, without duplicates.
func matchKnownRoles(values []string, roles config.RoleMap) []string {
	seen := make(map[string]bool, len(values))
	var matched []string
	for _, v := range values {
		if _, ok := roles[v]; !ok {
			continue
		}
		if seen[v] {
			continue
		}
		seen[v] = true
		matched = append(matched, v)
	}
	return matched
}

// ResolveRoleNames decides which role names are EFFECTIVE for a forward-auth
// entry, in the precedence order the design fixes:
//
//  1. headerRaw carries at least one KNOWN role name -> those, unified.
//  2. headerRaw is present but every value is unknown -> defaultRole alone
//     (never roleLocal, never "no rights" — verification points 7 and 12).
//  3. headerRaw is empty (the guard sent no role header at all, or an empty
//     one) -> roleLocal if an operator has assigned one, else defaultRole.
//
// roleLocal MUST be users.role_local — the value ONLY an operator ever
// writes, via PromoteUserRole (admin.go) — and NEVER users.role, the
// separate, merely-displayed "currently effective" column ForwardAuthLogin
// also maintains (internal/identity/forwardauth.go). Passing the effective
// column here instead would let a role a header asserted on some EARLIER
// entry silently survive into an entry where the header is absent —
// exactly the bug a first draft of this function had, caught in review:
// "liefert er keine [Rolle] -> die lokal zugewiesene Rolle gilt" is a
// promise about the OPERATOR's assignment, not about whatever the guard
// last happened to say.
func ResolveRoleNames(headerRaw string, roles config.RoleMap, defaultRole, roleLocal string) []string {
	if strings.TrimSpace(headerRaw) == "" {
		if roleLocal == "" {
			if defaultRole == "" {
				return nil
			}
			return []string{defaultRole}
		}
		return SplitRoleHeaderValues(roleLocal)
	}

	matched := matchKnownRoles(SplitRoleHeaderValues(headerRaw), roles)
	if len(matched) == 0 {
		if defaultRole == "" {
			return nil
		}
		return []string{defaultRole}
	}
	return matched
}

// JoinRoleNames is the exact inverse of how ResolveRoleNames parses
// roleLocal above — also the form users.role (the effective/display
// column, see Aufgabe 4) is stored in.
func JoinRoleNames(names []string) string {
	return strings.Join(names, ",")
}

// EffectivePermissions computes the union of every named role's rights.
// Role NAMES are what persists (users.role); the rights themselves are
// always looked up fresh against the current roles map, so a config change
// takes effect for an already-created user without a data migration (see
// the design doc, "Die Namen bestimmt der Betreiber, die Rechte sind
// fest").
func EffectivePermissions(names []string, roles config.RoleMap) (permissions map[config.Permission]bool, allLibraries bool, libraries []string) {
	permissions = make(map[config.Permission]bool)
	libSeen := make(map[string]bool)
	for _, name := range names {
		role, ok := roles[name]
		if !ok {
			continue
		}
		for p := range role.Permissions {
			permissions[p] = true
		}
		if role.AllLibraries {
			allLibraries = true
		}
		for _, lib := range role.Libraries {
			if libSeen[lib] {
				continue
			}
			libSeen[lib] = true
			libraries = append(libraries, lib)
		}
	}
	return permissions, allLibraries, libraries
}
