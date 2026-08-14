package api

import (
	"strings"
	"testing"
)

func TestInventoryAcceptsWellFormedRoutes(t *testing.T) {
	routes := []Route{
		{Pattern: "/healthz", Middleware: MiddlewarePublic},
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
	// "Unguarded" is expressed as MiddlewarePublic now that MiddlewareNone is
	// gone — a route on the app prefix claiming to need no check at all is
	// exactly what this test predates: it must still be rejected, whether
	// because it sits on the session prefix (checkPairing's prefix rule) or
	// because it is not on the public allow-list (see
	// TestInventoryRejectsMiddlewarePublicOnRouteNotOnAllowList further down
	// for that second reason in isolation).
	routes := []Route{{Pattern: "/api/v1/app/invites", Middleware: MiddlewarePublic}}

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
	routes := []Route{{Pattern: "GET /healthz", Middleware: MiddlewarePublic}}

	if errs := CheckInventory(routes); len(errs) != 0 {
		t.Errorf("CheckInventory() reported %d problems, want none: %v", len(errs), errs)
	}
}

func TestInventoryRejectsPatternWithoutPathAfterMethod(t *testing.T) {
	// A method-looking word with nothing after it is a typo, not a route. It
	// must be reported, not silently dropped or silently accepted. The
	// middleware value is irrelevant here — splitPattern fails before it is
	// ever consulted.
	routes := []Route{{Pattern: "GET", Middleware: MiddlewareSession}}

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

// --- Finding 3: checkRouting builds a real net/http.ServeMux to ask it which
// pattern serves a given request. Two patterns net/http itself cannot tell
// apart make ServeMux.Handle PANIC instead of returning an error — and a
// literal, well-formed route list is enough to trigger it (no server needs to
// start). CheckInventory must turn that into an ordinary finding, and it must
// not lose whatever checkPairing already collected in the process. ---

func TestInventoryReportsAmbiguousPatternPairInsteadOfPanicking(t *testing.T) {
	routes := []Route{
		{Pattern: "/api/v1/app/{resource}/{id}", Middleware: MiddlewareSession},
		{Pattern: "/api/v1/{provider}/app/{action}", Middleware: MiddlewareToken},
	}

	errs := CheckInventory(routes) // must return, not panic

	if !anyErrorNamesBoth(errs, routes[0].Pattern, routes[1].Pattern) {
		t.Fatalf("no finding names both %q and %q: %v", routes[0].Pattern, routes[1].Pattern, errs)
	}
}

func TestInventoryReportsExactDuplicateRouteInsteadOfPanicking(t *testing.T) {
	routes := []Route{
		{Pattern: "/api/v1/app/x", Middleware: MiddlewareSession},
		{Pattern: "/api/v1/app/x", Middleware: MiddlewareSession},
	}

	errs := CheckInventory(routes) // must return, not panic

	if !anyErrorContains(errs, routes[0].Pattern) {
		t.Fatalf("no finding names the duplicated route %q: %v", routes[0].Pattern, errs)
	}
}

func TestInventoryKeepsEarlierFindingsWhenARoutingConflictAlsoOccurs(t *testing.T) {
	// /stray sits below neither prefix — a checkPairing finding, from the
	// first, string-only stage. It must survive even though the other two
	// routes make the second stage, checkRouting, hit an ambiguous pair.
	routes := []Route{
		{Pattern: "/stray", Middleware: MiddlewareSession},
		{Pattern: "/api/v1/app/{resource}/{id}", Middleware: MiddlewareSession},
		{Pattern: "/api/v1/{provider}/app/{action}", Middleware: MiddlewareToken},
	}

	errs := CheckInventory(routes)

	if !anyErrorContains(errs, "/stray") {
		t.Errorf("findings %v lost the checkPairing finding about /stray", errs)
	}
	if !anyErrorNamesBoth(errs, routes[1].Pattern, routes[2].Pattern) {
		t.Errorf("findings %v are missing the checkRouting finding about the ambiguous pair", errs)
	}
}

// --- MiddlewarePublic: a named kind for paths deliberately reachable with no
// check at all, backed by an explicit allow-list (publicPaths in
// inventory.go) instead of a single hard-coded constant. A route carrying
// MiddlewarePublic is only accepted when its path is actually named on that
// list — the kind by itself grants nothing, or the new kind would just be
// the old healthPattern special case renamed. ---

func TestInventoryAcceptsHealthRouteWithMiddlewarePublic(t *testing.T) {
	routes := []Route{{Pattern: "/healthz", Middleware: MiddlewarePublic}}

	if errs := CheckInventory(routes); len(errs) != 0 {
		t.Errorf("CheckInventory() reported %d problems, want none: %v", len(errs), errs)
	}
}

// TestInventoryAcceptsInviteRedeemPatternWithMiddlewarePublic proves the
// allow-list is ready for the invite-redeem route before anyone registers
// it. No handler exists for "/invite/{token}" yet (see router.go) — this
// only exercises CheckInventory against a literal route, same as every other
// test in this file.
func TestInventoryAcceptsInviteRedeemPatternWithMiddlewarePublic(t *testing.T) {
	routes := []Route{{Pattern: "GET /invite/{token}", Middleware: MiddlewarePublic}}

	if errs := CheckInventory(routes); len(errs) != 0 {
		t.Errorf("CheckInventory() reported %d problems, want none: %v", len(errs), errs)
	}
}

// TestInventoryRejectsMiddlewarePublicOnRouteNotOnAllowList is the important
// case: MiddlewarePublic is not a free pass. A route that carries it without
// being named, with its reason, on publicPaths must be reported.
func TestInventoryRejectsMiddlewarePublicOnRouteNotOnAllowList(t *testing.T) {
	// /api/v1/app/invites sits squarely on the session prefix — a route there
	// claiming to be public is exactly the bypass this list exists to catch.
	routes := []Route{{Pattern: "/api/v1/app/invites", Middleware: MiddlewarePublic}}

	errs := CheckInventory(routes)
	if len(errs) == 0 {
		t.Fatal("CheckInventory() reported no problem, want one about the allow-list")
	}
	if !anyErrorContains(errs, "/api/v1/app/invites") {
		t.Errorf("findings %v do not name the offending route", errs)
	}
}

// TestInventoryRejectsSessionMiddlewareOnHealthPath is the symmetric case to
// TestInventoryAcceptsHealthRouteWithMiddlewarePublic: the health path stays
// rejected under any check other than the one the allow-list actually
// permits for it — MiddlewarePublic.
func TestInventoryRejectsSessionMiddlewareOnHealthPath(t *testing.T) {
	routes := []Route{{Pattern: "/healthz", Middleware: MiddlewareSession}}

	if errs := CheckInventory(routes); len(errs) == 0 {
		t.Fatal("CheckInventory() reported no problem, want one about the middleware")
	}
}

func anyErrorContains(errs []error, substr string) bool {
	for _, err := range errs {
		if strings.Contains(err.Error(), substr) {
			return true
		}
	}
	return false
}

func anyErrorNamesBoth(errs []error, a, b string) bool {
	for _, err := range errs {
		msg := err.Error()
		if strings.Contains(msg, a) && strings.Contains(msg, b) {
			return true
		}
	}
	return false
}

// --- Small, separate finding while investigating the above: a pattern with a
// host prefix instead of a leading "/" (e.g. "example.org/api/v1/app/x") was
// reported as "has no path and does not start with /" — wrong, it has a
// path, just behind a host. The route is still correctly rejected either way;
// only the wording was misleading. ---

func TestInventoryReportsHostPrefixedPatternAccurately(t *testing.T) {
	routes := []Route{{Pattern: "example.org/api/v1/app/x", Middleware: MiddlewareSession}}

	errs := CheckInventory(routes)
	if len(errs) == 0 {
		t.Fatal("CheckInventory() reported no problem, want one about the host-prefixed pattern")
	}
	if !anyErrorContains(errs, "example.org") {
		t.Errorf("findings %v do not mention the host prefix", errs)
	}
	for _, err := range errs {
		if strings.Contains(err.Error(), "has no path") {
			t.Errorf("finding %q still claims the pattern has no path, but it does", err)
		}
	}
}
