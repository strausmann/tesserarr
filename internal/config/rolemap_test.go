package config

import "testing"

func TestParseRoleMapParsesMultipleRolesWithGrantsAndLibraries(t *testing.T) {
	roles, err := ParseRoleMap("admin:administer+request+libraries=*,familie:request+libraries=*,gast:request+libraries=filme")
	if err != nil {
		t.Fatalf("ParseRoleMap() returned error: %v", err)
	}

	admin, ok := roles["admin"]
	if !ok {
		t.Fatal(`ParseRoleMap() result has no "admin" role`)
	}
	if !admin.Permissions[PermissionAdminister] || !admin.Permissions[PermissionRequest] {
		t.Errorf("admin.Permissions = %v, want administer and request", admin.Permissions)
	}
	if !admin.AllLibraries {
		t.Error("admin.AllLibraries = false, want true (libraries=*)")
	}

	gast, ok := roles["gast"]
	if !ok {
		t.Fatal(`ParseRoleMap() result has no "gast" role`)
	}
	if gast.AllLibraries {
		t.Error("gast.AllLibraries = true, want false")
	}
	if len(gast.Libraries) != 1 || gast.Libraries[0] != "filme" {
		t.Errorf("gast.Libraries = %v, want [filme]", gast.Libraries)
	}
	if gast.Permissions[PermissionAdminister] {
		t.Error("gast.Permissions contains administer, want only request")
	}
}

func TestParseRoleMapEmptyStringYieldsNilMap(t *testing.T) {
	roles, err := ParseRoleMap("")
	if err != nil {
		t.Fatalf("ParseRoleMap(\"\") returned error: %v", err)
	}
	if roles != nil {
		t.Errorf("ParseRoleMap(\"\") = %v, want nil", roles)
	}
}

func TestParseRoleMapRejectsEntryWithoutColon(t *testing.T) {
	if _, err := ParseRoleMap("admin"); err == nil {
		t.Fatal("ParseRoleMap(\"admin\") returned no error, want one about the missing colon")
	}
}

func TestParseRoleMapRejectsUnknownGrant(t *testing.T) {
	if _, err := ParseRoleMap("admin:fly"); err == nil {
		t.Fatal("ParseRoleMap(\"admin:fly\") returned no error, want one about the unknown grant")
	}
}

func TestParseRoleMapRejectsDuplicateRoleName(t *testing.T) {
	if _, err := ParseRoleMap("admin:request,admin:administer"); err == nil {
		t.Fatal("ParseRoleMap() with a duplicate role name returned no error")
	}
}

func TestParseRoleMapTrimsWhitespaceAroundEntries(t *testing.T) {
	roles, err := ParseRoleMap(" admin : administer , familie : request ")
	if err != nil {
		t.Fatalf("ParseRoleMap() returned error: %v", err)
	}
	if _, ok := roles["admin"]; !ok {
		t.Errorf("roles = %v, want an \"admin\" entry", roles)
	}
	if _, ok := roles["familie"]; !ok {
		t.Errorf("roles = %v, want a \"familie\" entry", roles)
	}
}
