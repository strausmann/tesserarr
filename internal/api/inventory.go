package api

import (
	"fmt"
	"net/http"
	"net/url"
	"strings"
)

// healthPattern is the health path — the one entry on publicPaths below that
// predates MiddlewarePublic and is, for backward compatibility, still also
// accepted with the older MiddlewareNone. See publicPaths for every other
// path deliberately reachable without a check.
const healthPattern = "/healthz"

// publicPath is one entry on the explicit allow-list of paths deliberately
// reachable with neither a session nor a token. CheckInventory only accepts
// MiddlewarePublic on a route whose path actually appears here — the
// middleware kind by itself grants nothing. Each entry names, in code, the
// reason it must be public, so a reviewer never has to go dig for it
// elsewhere.
type publicPath struct {
	// path is the bare path (after splitPattern has removed an optional
	// leading HTTP method). When prefix is true, path is matched as a
	// PREFIX instead of exactly — for a route whose trailing segment is
	// itself the credential, such as the invite token below.
	path   string
	prefix bool
	why    string
}

var publicPaths = []publicPath{
	{
		path: healthPattern,
		why:  "a monitoring probe carries no credential",
	},
	{
		path:   "/invite/",
		prefix: true,
		why:    "the invited person has no session yet — the token in the path is the credential",
	},
}

// isPublicPath reports whether path is named on publicPaths: a prefix entry
// (e.g. "/invite/") matches by prefix, everything else matches exactly.
func isPublicPath(path string) bool {
	for _, p := range publicPaths {
		if p.prefix {
			if strings.HasPrefix(path, p.path) {
				return true
			}
			continue
		}
		if path == p.path {
			return true
		}
	}
	return false
}

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

		if isPublicPath(path) {
			switch {
			case route.Middleware == MiddlewarePublic:
				// The intended, forward-looking case.
			case path == healthPattern && route.Middleware == MiddlewareNone:
				// Backward compatibility only: the health path predates
				// MiddlewarePublic and was, and remains, registered with
				// MiddlewareNone (see router.go). This exception is scoped
				// to the health path alone and is not extended to any other
				// entry on publicPaths — those are expected to use
				// MiddlewarePublic.
			default:
				problems = append(problems, fmt.Errorf(
					"route %q is on the explicit allow-list of public paths (see publicPaths) but carries %s, want public",
					route.Pattern, route.Middleware))
			}
			continue
		}

		if route.Middleware == MiddlewarePublic {
			// The symmetric case: MiddlewarePublic claimed for a path that is
			// NOT on the allow-list. Without this check, MiddlewarePublic
			// would be a free pass around every prefix rule below — the very
			// thing publicPaths exists to prevent.
			problems = append(problems, fmt.Errorf(
				"route %q carries public, but its path is not on the explicit allow-list of public paths "+
					"(see publicPaths) — add it there, with a reason, before using MiddlewarePublic on it",
				route.Pattern))
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
	registered := make([]string, 0, len(routes))
	failed := make(map[string]bool, len(routes))

	for _, route := range routes {
		if _, _, err := splitPattern(route.Pattern); err != nil {
			continue // already reported by checkPairing; ServeMux would panic on it
		}

		conflicts, ok := registerRoute(mux, route.Pattern, registered)
		if !ok {
			// Two patterns net/http.ServeMux itself cannot tell apart — it
			// would otherwise panic here and take down the whole check,
			// losing every finding checkPairing already collected. This is
			// a finding, not a crash: report it and move on.
			failed[route.Pattern] = true
			for _, other := range conflicts {
				problems = append(problems, fmt.Errorf(
					"route %q and route %q resolve to the same requests as far as the router "+
						"is concerned — net/http.ServeMux cannot tell them apart; give one of "+
						"the two patterns a more specific path",
					route.Pattern, other))
			}
			continue
		}

		middlewareOf[route.Pattern] = route.Middleware
		registered = append(registered, route.Pattern)
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
		if failed[route.Pattern] {
			continue // already reported above; it was never added to the router
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

// registerRoute adds pattern to mux, converting net/http.ServeMux's own
// conflict panic — raised when two patterns overlap and neither is more
// specific than the other, including two identical patterns — into a normal,
// recoverable outcome. A router ambiguity is exactly the kind of thing this
// inventory exists to report; it must never abort CheckInventory outright and
// take every already-collected finding down with it.
//
// On a conflict it also names which of the already-registered patterns the
// new one collides with, by re-trying each pairing in isolation (see
// patternsConflict). That is more reliable than parsing the text of
// net/http's panic, which is not a documented, stable interface.
func registerRoute(mux *http.ServeMux, pattern string, alreadyRegistered []string) (conflicts []string, ok bool) {
	defer func() {
		if recover() == nil {
			return
		}
		ok = false
		for _, existing := range alreadyRegistered {
			if patternsConflict(existing, pattern) {
				conflicts = append(conflicts, existing)
			}
		}
	}()

	mux.Handle(pattern, http.NotFoundHandler())
	return nil, true
}

// patternsConflict reports whether net/http.ServeMux itself would refuse to
// register a and b together, by trying exactly that on a throwaway mux that
// holds nothing else.
func patternsConflict(a, b string) (conflict bool) {
	defer func() {
		if recover() != nil {
			conflict = true
		}
	}()

	m := http.NewServeMux()
	m.Handle(a, http.NotFoundHandler())
	m.Handle(b, http.NotFoundHandler())
	return false
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
		if slash := strings.IndexByte(pattern, '/'); slash >= 0 {
			return "", "", fmt.Errorf(
				"has a path, but it is prefixed with a host (%q) instead of starting with %q — "+
					"host-scoped patterns are not supported here",
				pattern[:slash], "/")
		}
		return "", "", fmt.Errorf("has no path and does not start with %q", "/")
	}

	method, path = pattern[:i], pattern[i+1:]
	if !strings.HasPrefix(path, "/") {
		return "", "", fmt.Errorf("has method prefix %q but no path starting with %q", method, "/")
	}

	return method, path, nil
}
