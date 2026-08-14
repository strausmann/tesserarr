// Reads the double-submit CSRF cookie the session-space middleware sets
// (internal/api/csrf.go, requireCSRF / ensureCSRFCookie). The frontend's only
// job on this side of the pattern is to read the cookie's value back out and
// echo it into the X-CSRF-Token header (see api.ts) on every state-changing
// request against PrefixApp — GET/HEAD/OPTIONS/TRACE never need it, because
// the server's csrfSafeMethods never checks it for those either.
//
// CSRF_COOKIE_NAME MUST match csrfCookieName in internal/api/csrf.go
// byte-for-byte. There is no shared source of truth across the Go/TypeScript
// boundary for this one string — a mismatch here fails silently (every
// state-changing session-space request gets rejected with 403) rather than
// with a compile error, so if that cookie's name ever changes on the Go
// side, this constant has to change with it.
export const CSRF_COOKIE_NAME = "tesserarr_csrf";

/**
 * Extracts CSRF_COOKIE_NAME's value out of a cookie jar string. Defaults to
 * reading `document.cookie`, but accepts an explicit jar so the parsing
 * logic itself can be tested without depending on jsdom's cookie handling.
 *
 * Returns null when the cookie is missing OR empty — matching
 * requireCSRF's own treatment of `cookie.Value == ""` as "no cookie": a
 * caller that got null here and skipped the header would otherwise see the
 * server reject the request anyway, just later and with a less specific
 * signal.
 */
export function readCsrfToken(
  cookieJar: string = typeof document === "undefined" ? "" : document.cookie,
): string | null {
  if (!cookieJar) return null;

  for (const entry of cookieJar.split(";")) {
    const separator = entry.indexOf("=");
    if (separator === -1) continue;

    const name = entry.slice(0, separator).trim();
    if (name !== CSRF_COOKIE_NAME) continue;

    const rawValue = entry.slice(separator + 1).trim();
    if (!rawValue) return null;

    try {
      return decodeURIComponent(rawValue);
    } catch {
      // A malformed percent-escape is not this function's problem to
      // diagnose — the raw value is still usable as a token.
      return rawValue;
    }
  }

  return null;
}
