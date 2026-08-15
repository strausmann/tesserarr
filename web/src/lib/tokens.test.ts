import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { CSRF_COOKIE_NAME } from "./csrf";
import { CSRF_HEADER_NAME } from "./api";
import { createToken, fetchTokens, revokeToken, TOKENS_PATH } from "./tokens";

function clearCookies() {
  document.cookie.split(";").forEach((entry) => {
    const name = entry.split("=")[0]?.trim();
    if (!name) return;
    document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
  });
}

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

beforeEach(() => clearCookies());
afterEach(() => {
  clearCookies();
  vi.restoreAllMocks();
});

describe("fetchTokens", () => {
  it("maps each token's fields to camelCase", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse(200, [
        { hash: "h1", label: "sonarr", created_at: "2026-01-01T00:00:00Z", last_used_at: null, expires_at: null },
      ]),
    );

    const tokens = await fetchTokens(fetchMock);

    expect(tokens).toEqual([
      { hash: "h1", label: "sonarr", createdAt: "2026-01-01T00:00:00Z", lastUsedAt: null, expiresAt: null },
    ]);
  });

  it("throws on a non-2xx status", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 500 }));

    await expect(fetchTokens(fetchMock)).rejects.toThrow(/500/);
  });
});

describe("createToken", () => {
  it("sends label and ttl_seconds with the CSRF header, returns the raw token", async () => {
    document.cookie = `${CSRF_COOKIE_NAME}=roundtrip-token`;
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse(201, {
        token: "raw-token-value",
        hash: "h1",
        label: "sonarr",
        created_at: "2026-01-01T00:00:00Z",
        last_used_at: null,
        expires_at: null,
      }),
    );

    const created = await createToken("sonarr", 0, fetchMock);

    expect(created.token).toBe("raw-token-value");
    expect(created.label).toBe("sonarr");
    const [path, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(path).toBe(TOKENS_PATH);
    expect(JSON.parse(init.body as string)).toEqual({ label: "sonarr", ttl_seconds: 0 });
    const headers = new Headers(init.headers);
    expect(headers.get(CSRF_HEADER_NAME)).toBe("roundtrip-token");
  });

  it("still sends the request when no csrf cookie is present, without crashing", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse(201, {
        token: "raw-token-value",
        hash: "h1",
        label: "sonarr",
        created_at: "2026-01-01T00:00:00Z",
        last_used_at: null,
        expires_at: null,
      }),
    );

    await createToken("sonarr", 0, fetchMock);

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const headers = new Headers(init.headers);
    expect(headers.has(CSRF_HEADER_NAME)).toBe(false);
  });

  it("throws when the server rejects creation", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 400 }));

    await expect(createToken("", 0, fetchMock)).rejects.toThrow(/400/);
  });
});

describe("revokeToken", () => {
  it("sends a DELETE to the hash-scoped path with the CSRF header", async () => {
    document.cookie = `${CSRF_COOKIE_NAME}=roundtrip-token`;
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));

    await revokeToken("h1", fetchMock);

    const [path, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(path).toBe(`${TOKENS_PATH}/h1`);
    expect(init.method).toBe("DELETE");
    const headers = new Headers(init.headers);
    expect(headers.get(CSRF_HEADER_NAME)).toBe("roundtrip-token");
  });

  it("still sends the request when no csrf cookie is present, without crashing", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));

    await revokeToken("h1", fetchMock);

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const headers = new Headers(init.headers);
    expect(headers.has(CSRF_HEADER_NAME)).toBe(false);
  });

  it("URL-encodes a hash that needs it", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));

    await revokeToken("h/1 2", fetchMock);

    const [path] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(path).toBe(`${TOKENS_PATH}/h%2F1%202`);
  });

  it("throws when the server rejects revocation", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 500 }));

    await expect(revokeToken("h1", fetchMock)).rejects.toThrow(/500/);
  });
});
