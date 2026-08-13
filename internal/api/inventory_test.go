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

// --- Finding 2: a "METHOD /path" pattern (the only idiomatic way to do
// method-specific routing on net/http.ServeMux since Go 1.22) is rejected
// outright, because the pairing check compares the whole pattern string
// against the prefixes instead of the path following the method. ---

func TestInventoryAcceptsMethodPrefixedSessionRoute(t *testing.T) {
	routes := []Route{{Pattern: "POST /api/v1/app/search", Middleware: MiddlewareSession}}

	if errs := CheckInventory(routes); len(errs) != 0 {
		t.Errorf("CheckInventory() reported %d problems, want none: %v", len(errs), errs)
	}
}

func TestInventoryRejectsMethodPrefixedRouteWithWrongMiddleware(t *testing.T) {
	// The method prefix must not accidentally bypass the pairing check either:
	// this route sits on the token prefix but carries a session check.
	routes := []Route{{Pattern: "POST /api/v1/search", Middleware: MiddlewareSession}}

	if errs := CheckInventory(routes); len(errs) == 0 {
		t.Fatal("CheckInventory() reported no problem, want one about the middleware")
	}
}

func TestInventoryAcceptsMethodPrefixedHealthRoute(t *testing.T) {
	routes := []Route{{Pattern: "GET /healthz", Middleware: MiddlewareNone}}

	if errs := CheckInventory(routes); len(errs) != 0 {
		t.Errorf("CheckInventory() reported %d problems, want none: %v", len(errs), errs)
	}
}

func TestInventoryRejectsPatternWithoutPathAfterMethod(t *testing.T) {
	// A method-looking word with nothing after it is a typo, not a route. It
	// must be reported, not silently dropped or silently accepted.
	routes := []Route{{Pattern: "GET", Middleware: MiddlewareNone}}

	errs := CheckInventory(routes)
	if len(errs) == 0 {
		t.Fatal("CheckInventory() reported no problem, want one about the missing path")
	}
}

// --- Finding 1: PrefixToken ("/api/v1") is a prefix of PrefixApp
// ("/api/v1/app"). The pairing check above only ever looks at the pattern
// STRING, never at what net/http.ServeMux actually does with overlapping
// patterns. A token-space wildcard route can therefore pass the pairing
// check while, on the real router, silently swallowing every request under
// the session space too — CheckInventory must ask the router, not just
// compare strings. ---

func TestInventoryRejectsTokenWildcardShadowingSessionSpace(t *testing.T) {
	routes := []Route{{Pattern: "/api/v1/{path...}", Middleware: MiddlewareToken}}

	if errs := CheckInventory(routes); len(errs) == 0 {
		t.Fatal("CheckInventory() reported no problem, want one about the token wildcard shadowing the session space")
	}
}
