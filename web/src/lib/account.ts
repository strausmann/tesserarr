// The account (profile) half of the session-space API — mirrors
// internal/api/account.go on the Go side. Same "credentials: include", same
// origin, same double-submit CSRF pattern api.ts already uses for logout()
// (see csrf.ts for why the header and the cookie have to match byte for
// byte): every state-changing session-space request needs the CSRF header
// echoed from the cookie, every safe GET does not.
import { readCsrfToken } from "./csrf";
import { CSRF_HEADER_NAME } from "./api";

export const ACCOUNT_PATH = "/api/v1/app/account";

export interface Account {
  displayName: string;
  email: string;
  role: string;
}

// The wire shape internal/api/account.go actually sends/accepts —
// snake_case, like every other JSON body this frontend talks to (see
// api.ts's SessionStatus mapping for the same convention).
interface AccountBody {
  display_name: string;
  email: string;
  role: string;
}

/**
 * Reads the signed-in visitor's own account. A safe GET — never sends the
 * CSRF header, matching fetchSessionStatus's treatment of GET in api.ts.
 */
export async function fetchAccount(fetchImpl: typeof fetch = fetch): Promise<Account> {
  const response = await fetchImpl(ACCOUNT_PATH, { method: "GET", credentials: "include" });
  if (!response.ok) {
    throw new Error(`account request failed with status ${response.status}`);
  }
  const body = (await response.json()) as AccountBody;
  return { displayName: body.display_name, email: body.email, role: body.role };
}

/**
 * Updates the signed-in visitor's own email. A state-changing PATCH, so it
 * carries the double-submit CSRF header alongside the cookie the browser
 * attaches automatically. If no CSRF cookie is readable, the request is
 * still sent without the header rather than failing client-side first —
 * same reasoning as logout() in api.ts: the server is the single source of
 * truth for whether the request is valid.
 */
export async function updateAccountEmail(
  email: string,
  fetchImpl: typeof fetch = fetch,
): Promise<void> {
  const headers = new Headers({ "Content-Type": "application/json" });
  const token = readCsrfToken();
  if (token) headers.set(CSRF_HEADER_NAME, token);

  const response = await fetchImpl(ACCOUNT_PATH, {
    method: "PATCH",
    credentials: "include",
    headers,
    body: JSON.stringify({ email }),
  });
  if (!response.ok) {
    throw new Error(`account update failed with status ${response.status}`);
  }
}
