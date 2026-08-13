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
		_, path, err := splitPattern(route.Pattern)
		if err != nil {
			problems = append(problems, fmt.Errorf("route %q: %w", route.Pattern, err))
			continue
		}

		if path == healthPattern {
			if route.Middleware != MiddlewareNone {
				problems = append(problems, fmt.Errorf(
					"route %q is the health path and must carry no check, but carries %s",
					route.Pattern, route.Middleware))
			}
			continue
		}

		onApp := strings.HasPrefix(path, PrefixApp+"/") || path == PrefixApp
		// PrefixToken is a prefix of PrefixApp, so "on the token space" means
		// "below /api/v1 but not below /api/v1/app".
		onToken := (strings.HasPrefix(path, PrefixToken+"/") || path == PrefixToken) && !onApp

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

// splitPattern separates the optional leading HTTP method from a route
// pattern, as accepted by net/http.ServeMux since Go 1.22 ("METHOD /path" or
// just "/path"), and returns the bare path. It is the only place that knows
// this syntax, so the prefix/middleware pairing check above can work on the
// path alone.
//
// A pattern that looks like it carries a method but has no path after it is
// reported as an error rather than silently ignored or silently accepted —
// it is almost certainly a typo, and a gate that lets typos through where a
// route was clearly intended defeats its own purpose.
func splitPattern(pattern string) (method, path string, err error) {
	if strings.HasPrefix(pattern, "/") {
		return "", pattern, nil
	}

	i := strings.IndexByte(pattern, ' ')
	if i < 0 {
		return "", "", fmt.Errorf("has no path and does not start with %q", "/")
	}

	method, path = pattern[:i], pattern[i+1:]
	if !strings.HasPrefix(path, "/") {
		return "", "", fmt.Errorf("has method prefix %q but no path starting with %q", method, "/")
	}

	return method, path, nil
}
