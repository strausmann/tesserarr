// The account (profile) half of the session-space API — mirrors
// internal/api/account.go on the Go side: a single, entirely read-only GET.
// Same "credentials: include", same origin as every other session-space
// request this frontend makes (see api.ts's fetchSessionStatus). There is
// no CSRF concern here at all — a safe GET never carries the double-submit
// header api.ts's state-changing requests do (see csrf.ts) — because there
// is nothing left on this route that changes state: email arrives
// exclusively from a trusted source (an OIDC provider's claims or a
// forward-auth guard's header), never from the signed-in visitor
// themselves.
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
