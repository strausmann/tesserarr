// The frontend's only data path — see ADR-0005 ("/api/v1 is the ONLY data
// path the interface has") and ADR-0007 (the browser holds a session
// cookie, never a provider token). Every call here goes through PrefixApp
// ("/api/v1/app", internal/api/router.go) and therefore carries
// credentials: "include" so the session and CSRF cookies travel with it —
// this app is served from the same origin as the API (one Go binary, one
// origin), so that is enough; no CORS configuration is needed.
import { readCsrfToken } from "./csrf";

export const SESSION_STATUS_PATH = "/api/v1/app/session";
export const LOGOUT_PATH = "/api/v1/app/logout";

// Must match csrfHeaderName in internal/api/csrf.go. http.Header
// canonicalizes lookups server-side, and the Fetch API's Headers does the
// same client-side, so the exact casing here does not affect matching — it
// is written this way only for readability.
export const CSRF_HEADER_NAME = "X-CSRF-Token";

export interface SessionStatus {
  authenticated: boolean;
  userId: string | null;
}

/**
 * Asks the session-space status route (handleSessionStatus,
 * internal/api/session_status.go) who — if anyone — this browser is logged
 * in as. This is a safe GET: it never sends the CSRF header, because
 * requireCSRF (internal/api/csrf.go) never checks it on a safe method
 * either.
 *
 * A 401 is not an error from this function's point of view — it is the
 * expected, common answer for a first-time visitor — so it resolves to
 * {authenticated: false}, not a thrown error. Any OTHER non-2xx status
 * (a 500, say) is a genuine failure and is thrown, so a caller cannot
 * mistake "the server is broken" for "nobody is logged in".
 */
export async function fetchSessionStatus(
  fetchImpl: typeof fetch = fetch,
): Promise<SessionStatus> {
  const response = await fetchImpl(SESSION_STATUS_PATH, {
    method: "GET",
    credentials: "include",
  });

  if (response.status === 401) {
    return { authenticated: false, userId: null };
  }
  if (!response.ok) {
    throw new Error(`session status request failed with status ${response.status}`);
  }

  const body = (await response.json()) as { user_id?: string };
  return { authenticated: true, userId: body.user_id ?? null };
}

/**
 * Ends the current session. This is a state-changing POST against the
 * session space, so — unlike fetchSessionStatus — it must carry the
 * double-submit CSRF header alongside the cookie the browser attaches
 * automatically. See csrf.ts for why the two have to match exactly.
 *
 * If no CSRF cookie is readable (e.g. it expired, or third-party cookie
 * blocking stripped it), the request is still sent without the header
 * rather than failing client-side first — the server is the single source
 * of truth for whether the request is valid, and returns 403 either way if
 * the header is missing or wrong.
 */
export async function logout(fetchImpl: typeof fetch = fetch): Promise<void> {
  const headers = new Headers();
  const token = readCsrfToken();
  if (token) {
    headers.set(CSRF_HEADER_NAME, token);
  }

  const response = await fetchImpl(LOGOUT_PATH, {
    method: "POST",
    credentials: "include",
    headers,
  });

  if (!response.ok) {
    throw new Error(`logout request failed with status ${response.status}`);
  }
}
