package config

import (
	"fmt"
	"strings"
)

// Permission is one of the fixed rights Tesserarr's role system knows. The
// set is closed on purpose — operators name roles freely, but a role can
// only carry rights Tesserarr actually enforces somewhere, so an unknown
// grant in TESSERARR_ROLE_MAP is a configuration error, not a silently
// ignored typo.
type Permission string

const (
	PermissionAdminister Permission = "administer"
	PermissionRequest    Permission = "request"
)

// Role is one named entry from TESSERARR_ROLE_MAP.
type Role struct {
	Name         string
	Permissions  map[Permission]bool
	AllLibraries bool
	Libraries    []string
}

// RoleMap is the parsed form of TESSERARR_ROLE_MAP, keyed by role name.
type RoleMap map[string]Role

// ParseRoleMap parses TESSERARR_ROLE_MAP's grammar:
//
//	<role>[,<role>...]
//	role  = name ":" grant["+"grant...]
//	grant = "administer" | "request" | "libraries=" libs
//	libs  = "*" | lib["|"lib...]
//
// An empty (or all-whitespace) raw string yields a nil RoleMap and no
// error — TESSERARR_ROLE_MAP is optional, the same convention
// parsePrefixes already uses below for TESSERARR_TRUSTED_PROXIES.
func ParseRoleMap(raw string) (RoleMap, error) {
	if strings.TrimSpace(raw) == "" {
		return nil, nil
	}

	roles := make(RoleMap)
	for _, entry := range strings.Split(raw, ",") {
		entry = strings.TrimSpace(entry)
		if entry == "" {
			continue
		}
		role, err := parseRole(entry)
		if err != nil {
			return nil, err
		}
		if _, exists := roles[role.Name]; exists {
			return nil, fmt.Errorf("role %q is defined twice", role.Name)
		}
		roles[role.Name] = role
	}
	return roles, nil
}

func parseRole(entry string) (Role, error) {
	name, grants, ok := strings.Cut(entry, ":")
	name = strings.TrimSpace(name)
	if !ok || name == "" {
		return Role{}, fmt.Errorf("%q is not a valid role entry, want name:grant[+grant...]", entry)
	}

	role := Role{Name: name, Permissions: make(map[Permission]bool)}
	for _, grant := range strings.Split(grants, "+") {
		grant = strings.TrimSpace(grant)
		if grant == "" {
			return Role{}, fmt.Errorf("role %q has an empty grant", name)
		}

		if libs, hasLibs := strings.CutPrefix(grant, "libraries="); hasLibs {
			if libs == "*" {
				role.AllLibraries = true
				continue
			}
			for _, lib := range strings.Split(libs, "|") {
				lib = strings.TrimSpace(lib)
				if lib == "" {
					continue
				}
				role.Libraries = append(role.Libraries, lib)
			}
			continue
		}

		switch Permission(grant) {
		case PermissionAdminister, PermissionRequest:
			role.Permissions[Permission(grant)] = true
		default:
			return Role{}, fmt.Errorf("role %q names unknown grant %q", name, grant)
		}
	}
	return role, nil
}
