package api

import (
	"fmt"
	"net/http"
	"net/url"
	"strings"
)

// healthPattern is the single route allowed to carry no check. A monitoring
// probe has no credential, so the liveness path cannot require one.
const healthPattern = "/healthz"

// CheckInventory validates every registered route two independent ways and
// returns the union of what each finds:
//
//   - checkPairing works on the pattern STRING alone: does the path (after
//     stripping an optional leading HTTP method, see splitPattern) start with
//     the prefix its middleware is supposed to guard.
//   - checkRouting works on what net/http.ServeMux actually does with those
//     patterns. A route can pass the string check and still be served by a
//     completely different, wrongly-guarded pattern: PrefixToken ("/api/v1")
//     is a prefix of PrefixApp ("/api/v1/app"), so a token-space wildcard
//     such as "/api/v1/{path...}" resolves, on the real router, for every
//     session-space request too — nothing about the pattern string shows
//     that. Only asking the router catches it.
//
// It exists because the separation of the two path spaces does not enforce
// itself. A test that checks today's routes secures today's state; a route
// added next year under the wrong prefix — or shadowed by an existing one —
// triggers nothing on its own. This function checks the rule instead,
// against the routes the running server actually registered.
func CheckInventory(routes []Route) []error {
	var problems []error
	problems = append(problems, checkPairing(routes)...)
	problems = append(problems, checkRouting(routes)...)
	return problems
}

// checkPairing compares each route's path prefix against its middleware,
// working from the pattern string alone. It cannot see routing precedence —
// see checkRouting for that half.
func checkPairing(routes []Route) []error {
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

// canarySuffixes are session-space paths that are deliberately never
// registered. If the running mux serves one of them anyway, some OTHER
// pattern — necessarily one that was not written to match this exact path —
// is absorbing it. Two depths are checked on purpose: a single-segment
// wildcard ("{id}") would only shadow the first, a trailing-remainder
// wildcard ("{path...}") shadows both.
var canarySuffixes = []string{"/__canary__", "/__canary__/nested"}

// checkRouting asks the real net/http.ServeMux which registered pattern
// actually serves a request, and flags it whenever a session-space path —
// whether an existing route or a deliberately unregistered canary — ends up
// served by a pattern carrying MiddlewareToken. checkPairing cannot see this:
// it only ever compares pattern strings, never routing precedence, and
// ServeMux resolves overlapping patterns by specificity, not by registration
// order or by which prefix a human reader would say a pattern "belongs" to.
func checkRouting(routes []Route) []error {
	var problems []error

	mux := http.NewServeMux()
	middlewareOf := make(map[string]Middleware, len(routes))
	for _, route := range routes {
		if _, _, err := splitPattern(route.Pattern); err != nil {
			continue // already reported by checkPairing; ServeMux would panic on it
		}
		middlewareOf[route.Pattern] = route.Middleware
		mux.Handle(route.Pattern, http.NotFoundHandler())
	}

	winner := func(method, path string) string {
		if method == "" {
			method = http.MethodGet
		}
		_, matched := mux.Handler(&http.Request{Method: method, URL: &url.URL{Path: path}})
		return matched
	}

	for _, route := range routes {
		method, path, err := splitPattern(route.Pattern)
		if err != nil {
			continue // already reported by checkPairing
		}
		if path == healthPattern {
			continue
		}
		if !strings.HasPrefix(path, PrefixApp+"/") && path != PrefixApp {
			continue // this check only concerns session-space shadowing
		}

		matched := winner(method, concretePath(path))
		if matched != route.Pattern {
			if mw, ok := middlewareOf[matched]; ok && mw == MiddlewareToken {
				problems = append(problems, fmt.Errorf(
					"route %q is on the session path space, but the running server actually "+
						"serves it with pattern %q, which carries token",
					route.Pattern, matched))
			}
		}
	}

	for _, suffix := range canarySuffixes {
		canary := PrefixApp + suffix
		matched := winner("", canary)
		if matched == "" {
			continue // no pattern claims it — correct, nothing to report
		}
		if mw, ok := middlewareOf[matched]; ok && mw == MiddlewareToken {
			problems = append(problems, fmt.Errorf(
				"unregistered session-space path %q is served by token pattern %q — "+
					"the token space is shadowing the session space",
				canary, matched))
		}
	}

	return problems
}

// concretePath turns a route pattern's wildcards into one concrete path a
// real request could target, so checkRouting can ask the mux what actually
// serves it. A single-segment wildcard ("{id}") becomes one literal segment;
// the trailing-remainder wildcard ("{path...}") becomes two literal segments
// — enough to prove whether ServeMux resolves the path to this pattern or a
// different, wrongly-paired one.
//
// This is a representative sample, not an exhaustive one: it tries exactly
// one concrete value per pattern. A route whose correctness depends on the
// SPECIFIC value taken by a wildcard (middleware that treats "{id}=self"
// differently from "{id}=other", say) is outside what this function's single
// sample can see — the same is true of a wildcard shadow that only manifests
// for a wildcard value this substitution does not happen to produce (unlikely
// for the plain "{name}"/"{name...}" ServeMux syntax, since matching there
// does not depend on the substring's content, but this function does not
// attempt to prove that in general).
func concretePath(path string) string {
	segments := strings.Split(path, "/")
	for i, seg := range segments {
		switch {
		case strings.HasPrefix(seg, "{") && strings.HasSuffix(seg, "...}"):
			segments[i] = "x/y"
		case strings.HasPrefix(seg, "{") && strings.HasSuffix(seg, "}"):
			segments[i] = "x"
		}
	}
	return strings.Join(segments, "/")
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
