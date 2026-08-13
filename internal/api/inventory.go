package api

import (
	"fmt"
	"strings"
)

// healthPattern is the single route allowed to carry no check. A monitoring
// probe has no credential, so the liveness path cannot require one.
const healthPattern = "/healthz"

// CheckInventory validates the pairing of path prefix and middleware for every
// registered route and returns one error per problem found.
//
// It exists because the separation of the two path spaces does not enforce
// itself. A test that checks today's routes secures today's state; a route
// added next year under the wrong prefix triggers nothing — it is simply there,
// with the wrong check in front of it. This function checks the rule instead,
// against the routes the running server actually registered.
func CheckInventory(routes []Route) []error {
	var problems []error

	for _, route := range routes {
		if route.Pattern == healthPattern {
			if route.Middleware != MiddlewareNone {
				problems = append(problems, fmt.Errorf(
					"route %q is the health path and must carry no check, but carries %s",
					route.Pattern, route.Middleware))
			}
			continue
		}

		onApp := strings.HasPrefix(route.Pattern, PrefixApp+"/") || route.Pattern == PrefixApp
		// PrefixToken is a prefix of PrefixApp, so "on the token space" means
		// "below /api/v1 but not below /api/v1/app".
		onToken := (strings.HasPrefix(route.Pattern, PrefixToken+"/") || route.Pattern == PrefixToken) && !onApp

		switch {
		case !onApp && !onToken:
			problems = append(problems, fmt.Errorf(
				"route %q sits below neither %q nor %q", route.Pattern, PrefixApp, PrefixToken))
		case onApp && route.Middleware != MiddlewareSession:
			problems = append(problems, fmt.Errorf(
				"route %q is on the session path space but carries %s, want session",
				route.Pattern, route.Middleware))
		case onToken && route.Middleware != MiddlewareToken:
			problems = append(problems, fmt.Errorf(
				"route %q is on the token path space but carries %s, want token",
				route.Pattern, route.Middleware))
		}
	}

	return problems
}
