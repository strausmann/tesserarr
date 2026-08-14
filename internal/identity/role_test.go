package identity

import (
	"reflect"
	"testing"

	"github.com/strausmann/tesserarr/internal/config"
)

func TestSplitRoleHeaderValuesSplitsOnCommaAndWhitespace(t *testing.T) {
	got := SplitRoleHeaderValues("familie, gast  admin")
	want := []string{"familie", "gast", "admin"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("SplitRoleHeaderValues() = %v, want %v", got, want)
	}
}

func TestSplitRoleHeaderValuesDropsEmptyPartsAndSurroundingWhitespace(t *testing.T) {
	got := SplitRoleHeaderValues(" , familie ,, gast , ")
	want := []string{"familie", "gast"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("SplitRoleHeaderValues() = %v, want %v", got, want)
	}
}

func TestSplitRoleHeaderValuesEmptyStringYieldsEmptySlice(t *testing.T) {
	got := SplitRoleHeaderValues("")
	if len(got) != 0 {
		t.Errorf("SplitRoleHeaderValues(\"\") = %v, want empty", got)
	}
}

// JoinRoleNames itself has no dedicated test step in the plan — it is only
// exercised end-to-end once Aufgabe 4 writes users.role. Added here anyway:
// it is a new exported function like any other, and role.go otherwise
// leaves it at 0% coverage against this package's 90% floor
// (.claude/rules/test-coverage-pflicht.md in homelab-management).
func TestJoinRoleNamesIsTheInverseOfSplit(t *testing.T) {
	names := []string{"familie", "gast"}
	got := JoinRoleNames(names)
	want := "familie,gast"
	if got != want {
		t.Errorf("JoinRoleNames(%v) = %q, want %q", names, got, want)
	}

	roundTrip := SplitRoleHeaderValues(got)
	if !reflect.DeepEqual(roundTrip, names) {
		t.Errorf("SplitRoleHeaderValues(JoinRoleNames(%v)) = %v, want %v", names, roundTrip, names)
	}
}

func TestJoinRoleNamesEmptySliceYieldsEmptyString(t *testing.T) {
	if got := JoinRoleNames(nil); got != "" {
		t.Errorf("JoinRoleNames(nil) = %q, want empty string", got)
	}
}

func testRoles(t *testing.T) config.RoleMap {
	t.Helper()
	roles, err := config.ParseRoleMap("admin:administer+request,familie:request,gast:request")
	if err != nil {
		t.Fatalf("config.ParseRoleMap() returned error: %v", err)
	}
	return roles
}

// Verifikationspunkt 11: mehrere bekannte Rollen werden vereinigt, beide
// Trenner funktionieren, eine Mischung aus bekannt/unbekannt behält nur die
// bekannten.
func TestResolveRoleNamesUnifiesMultipleKnownRolesFromHeader(t *testing.T) {
	got := ResolveRoleNames("familie,gast", testRoles(t), "gast", "")
	want := []string{"familie", "gast"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("ResolveRoleNames() = %v, want %v", got, want)
	}
}

func TestResolveRoleNamesUnifiesWithWhitespaceSeparator(t *testing.T) {
	got := ResolveRoleNames("familie gast", testRoles(t), "gast", "")
	want := []string{"familie", "gast"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("ResolveRoleNames() = %v, want %v", got, want)
	}
}

func TestResolveRoleNamesIgnoresUnknownValuesAmongKnownOnes(t *testing.T) {
	got := ResolveRoleNames("familie,typo,gast", testRoles(t), "gast", "")
	want := []string{"familie", "gast"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("ResolveRoleNames() = %v, want %v (typo dropped)", got, want)
	}
}

// Verifikationspunkt 7 und 12: eine Kopfzeile mit AUSSCHLIESSLICH unbekannten
// Werten ergibt die Standard-Rolle — nicht "keine Rechte", nicht die
// lokale Zuweisung, selbst wenn eine gesetzt war.
func TestResolveRoleNamesUnknownOnlyHeaderYieldsDefaultNotLocalRole(t *testing.T) {
	got := ResolveRoleNames("typo-only", testRoles(t), "gast", "admin")
	want := []string{"gast"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("ResolveRoleNames() = %v, want %v (the default, not the local role \"admin\")", got, want)
	}
}

func TestResolveRoleNamesUnknownOnlyHeaderYieldsDefaultNotNoRights(t *testing.T) {
	got := ResolveRoleNames("typo-only", testRoles(t), "gast", "")
	want := []string{"gast"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("ResolveRoleNames() = %v, want %v (the default, not empty/no rights)", got, want)
	}
}

// Verifikationspunkt 10: Vorrang Kopfzeile > lokale Zuweisung > Standard-Rolle.
func TestResolveRoleNamesHeaderBeatsLocalRole(t *testing.T) {
	got := ResolveRoleNames("familie", testRoles(t), "gast", "admin")
	want := []string{"familie"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("ResolveRoleNames() = %v, want %v (header wins over the local role \"admin\")", got, want)
	}
}

func TestResolveRoleNamesEmptyHeaderFallsBackToLocalRole(t *testing.T) {
	got := ResolveRoleNames("", testRoles(t), "gast", "admin")
	want := []string{"admin"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("ResolveRoleNames() = %v, want %v (no header at all -> local role)", got, want)
	}
}

func TestResolveRoleNamesEmptyHeaderAndNoLocalRoleFallsBackToDefault(t *testing.T) {
	got := ResolveRoleNames("", testRoles(t), "gast", "")
	want := []string{"gast"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("ResolveRoleNames() = %v, want %v (no header, no local role -> default)", got, want)
	}
}

func TestResolveRoleNamesWhitespaceOnlyHeaderCountsAsAbsent(t *testing.T) {
	got := ResolveRoleNames("   ", testRoles(t), "gast", "admin")
	want := []string{"admin"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("ResolveRoleNames() = %v, want %v", got, want)
	}
}

// TestResolveRoleNamesHeaderThenNoHeaderDropsToLocalNotToThePreviousHeaderRole
// is the case team-lead's review specifically called out: a header sets an
// EFFECTIVE role on one entry, but that must never leak into a LATER call
// as if it were the local assignment. Simulates two successive entries by
// calling ResolveRoleNames twice, passing "admin" as roleLocal on BOTH
// calls (a caller that had made the first-draft mistake — feeding the
// EFFECTIVE result of call 1 back in as roleLocal for call 2 — would pass
// "familie" the second time here, not "admin"; this test only accepts the
// deliberately correct wiring where roleLocal never changes just because a
// header once asserted something else).
func TestResolveRoleNamesHeaderThenNoHeaderDropsToLocalNotToThePreviousHeaderRole(t *testing.T) {
	roles := testRoles(t)
	const roleLocal = "admin" // what an operator actually assigned, unaffected by any header

	first := ResolveRoleNames("familie", roles, "gast", roleLocal)
	if want := []string{"familie"}; !reflect.DeepEqual(first, want) {
		t.Fatalf("first entry: ResolveRoleNames() = %v, want %v", first, want)
	}

	second := ResolveRoleNames("", roles, "gast", roleLocal)
	if want := []string{"admin"}; !reflect.DeepEqual(second, want) {
		t.Errorf("second entry (no header): ResolveRoleNames() = %v, want %v (the LOCAL role, not \"familie\" from the first entry)", second, want)
	}
}

func TestEffectivePermissionsUnionsAcrossMultipleRoles(t *testing.T) {
	roles, err := config.ParseRoleMap("familie:request+libraries=*,gast:request+libraries=filme")
	if err != nil {
		t.Fatalf("config.ParseRoleMap() returned error: %v", err)
	}

	permissions, allLibraries, libraries := EffectivePermissions([]string{"familie", "gast"}, roles)

	if !permissions[config.PermissionRequest] {
		t.Error("permissions does not contain request")
	}
	if !allLibraries {
		t.Error("allLibraries = false, want true (familie grants libraries=*)")
	}
	if len(libraries) != 0 {
		// allLibraries already covers everything; the explicit gast list is
		// redundant once any role grants "*", but is still returned as-is
		// for callers that want to display it — EffectivePermissions does
		// not need to special-case this.
		t.Logf("libraries = %v (redundant once allLibraries is true, not an error)", libraries)
	}
}

func TestEffectivePermissionsIgnoresUnknownRoleNames(t *testing.T) {
	roles, err := config.ParseRoleMap("familie:request")
	if err != nil {
		t.Fatalf("config.ParseRoleMap() returned error: %v", err)
	}

	permissions, allLibraries, libraries := EffectivePermissions([]string{"familie", "no-such-role"}, roles)

	if !permissions[config.PermissionRequest] {
		t.Error("permissions does not contain request")
	}
	if allLibraries {
		t.Error("allLibraries = true, want false")
	}
	if len(libraries) != 0 {
		t.Errorf("libraries = %v, want empty", libraries)
	}
}
