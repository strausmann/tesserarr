package api

import (
	"strings"
	"testing"
)

func TestInventoryAcceptsWellFormedRoutes(t *testing.T) {
	routes := []Route{
		{Pattern: "/healthz", Middleware: MiddlewareNone},
		{Pattern: "/api/v1/app/session", Middleware: MiddlewareSession},
		{Pattern: "/api/v1/search", Middleware: MiddlewareToken},
	}

	if errs := CheckInventory(routes); len(errs) != 0 {
		t.Errorf("CheckInventory() reported %d problems, want none: %v", len(errs), errs)
	}
}

func TestInventoryRejectsRouteOutsideBothPrefixes(t *testing.T) {
	routes := []Route{{Pattern: "/stray", Middleware: MiddlewareSession}}

	errs := CheckInventory(routes)
	if len(errs) == 0 {
		t.Fatal("CheckInventory() reported no problem, want one about the prefix")
	}
	if !strings.Contains(errs[0].Error(), "/stray") {
		t.Errorf("error %q does not name the offending route", errs[0])
	}
}

func TestInventoryRejectsSessionMiddlewareOnTokenPrefix(t *testing.T) {
	// /api/v1/search sits on the token prefix; a session check in front of it
	// is exactly the mix-up this gate exists to catch.
	routes := []Route{{Pattern: "/api/v1/search", Middleware: MiddlewareSession}}

	if errs := CheckInventory(routes); len(errs) == 0 {
		t.Fatal("CheckInventory() reported no problem, want one about the middleware")
	}
}

func TestInventoryRejectsTokenMiddlewareOnAppPrefix(t *testing.T) {
	// The symmetric case. It gets its own test on purpose: a shared "cookie OR
	// header" helper would pass the previous test and fail this one.
	routes := []Route{{Pattern: "/api/v1/app/invites", Middleware: MiddlewareToken}}

	if errs := CheckInventory(routes); len(errs) == 0 {
		t.Fatal("CheckInventory() reported no problem, want one about the middleware")
	}
}

func TestInventoryRejectsUnguardedRouteOtherThanHealth(t *testing.T) {
	routes := []Route{{Pattern: "/api/v1/app/invites", Middleware: MiddlewareNone}}

	if errs := CheckInventory(routes); len(errs) == 0 {
		t.Fatal("CheckInventory() reported no problem, want one about the missing check")
	}
}

// TestRunningServerPassesInventory is the gate proper: it reads the routes from
// a real, constructed API rather than from a literal, so a route added later is
// covered without anyone remembering this test.
func TestRunningServerPassesInventory(t *testing.T) {
	a := newTestAPI(t)

	if errs := CheckInventory(a.Routes()); len(errs) != 0 {
		for _, err := range errs {
			t.Errorf("inventory problem: %v", err)
		}
	}
}
