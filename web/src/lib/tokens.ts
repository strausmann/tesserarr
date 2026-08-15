// The API-token half of the session-space API — mirrors
// internal/api/tokens.go on the Go side. Same "credentials: include" /
// double-submit CSRF pattern as account.ts and api.ts's logout(). The raw
// token value only ever appears in createToken's response — fetchTokens
// (list) and the stored ApiToken shape never carry it, matching the design
// doc's "shown exactly once" rule on the server side; nothing on this side
// tries to reconstruct it afterwards either (see page.tsx / page.test.tsx).
import { readCsrfToken } from "./csrf";
import { CSRF_HEADER_NAME } from "./api";

export const TOKENS_PATH = "/api/v1/app/tokens";

export interface ApiToken {
  hash: string;
  label: string;
  createdAt: string;
  lastUsedAt: string | null;
  expiresAt: string | null;
}

// The wire shape internal/api/tokens.go sends for each listed/created
// token — snake_case, like AccountBody in account.ts.
interface TokenBody {
  hash: string;
  label: string;
  created_at: string;
  last_used_at: string | null;
  expires_at: string | null;
}

function toApiToken(body: TokenBody): ApiToken {
  return {
    hash: body.hash,
    label: body.label,
    createdAt: body.created_at,
    lastUsedAt: body.last_used_at,
    expiresAt: body.expires_at,
  };
}

/**
 * Lists the signed-in visitor's own API tokens. A safe GET — never sends
 * the CSRF header, matching every other GET in this frontend (see
 * fetchSessionStatus in api.ts and fetchAccount in account.ts).
 */
export async function fetchTokens(fetchImpl: typeof fetch = fetch): Promise<ApiToken[]> {
  const response = await fetchImpl(TOKENS_PATH, { method: "GET", credentials: "include" });
  if (!response.ok) {
    throw new Error(`tokens request failed with status ${response.status}`);
  }
  const body = (await response.json()) as TokenBody[];
  return body.map(toApiToken);
}

export interface CreatedToken extends ApiToken {
  /** The raw token value. Present ONLY in this creation response — never
   * again, not in fetchTokens, not stored anywhere by this module. */
  token: string;
}

interface CreateTokenBody extends TokenBody {
  token: string;
}

/**
 * Creates a new API token with the given label. `ttlSeconds` of 0 means
 * "no expiry", matching internal/api/tokens.go's own default. A
 * state-changing POST, so it carries the CSRF header like revokeToken below.
 */
export async function createToken(
  label: string,
  ttlSeconds = 0,
  fetchImpl: typeof fetch = fetch,
): Promise<CreatedToken> {
  const headers = new Headers({ "Content-Type": "application/json" });
  const csrf = readCsrfToken();
  if (csrf) headers.set(CSRF_HEADER_NAME, csrf);

  const response = await fetchImpl(TOKENS_PATH, {
    method: "POST",
    credentials: "include",
    headers,
    body: JSON.stringify({ label, ttl_seconds: ttlSeconds }),
  });
  if (!response.ok) {
    throw new Error(`token creation failed with status ${response.status}`);
  }
  const body = (await response.json()) as CreateTokenBody;
  return { ...toApiToken(body), token: body.token };
}

/**
 * Revokes an existing token by its hash. A state-changing DELETE against
 * the hash-scoped path, carrying the CSRF header like the other mutations
 * in this module.
 */
export async function revokeToken(hash: string, fetchImpl: typeof fetch = fetch): Promise<void> {
  const headers = new Headers();
  const csrf = readCsrfToken();
  if (csrf) headers.set(CSRF_HEADER_NAME, csrf);

  const response = await fetchImpl(`${TOKENS_PATH}/${encodeURIComponent(hash)}`, {
    method: "DELETE",
    credentials: "include",
    headers,
  });
  if (!response.ok) {
    throw new Error(`token revoke failed with status ${response.status}`);
  }
}
