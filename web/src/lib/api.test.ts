import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { CSRF_COOKIE_NAME } from "./csrf";
import { fetchSessionStatus, logout, SESSION_STATUS_PATH, LOGOUT_PATH, CSRF_HEADER_NAME } from "./api";

function clearCookies() {
  document.cookie.split(";").forEach((entry) => {
    const name = entry.split("=")[0]?.trim();
    if (!name) return;
    document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
  });
}

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

beforeEach(() => {
  clearCookies();
});

afterEach(() => {
  clearCookies();
  vi.restoreAllMocks();
});

describe("fetchSessionStatus", () => {
  it("reports authenticated with the user id on 200", async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse(200, { user_id: "user-1" }));

    const status = await fetchSessionStatus(fetchMock);

    expect(status).toEqual({ authenticated: true, userId: "user-1" });
    expect(fetchMock).toHaveBeenCalledWith(
      SESSION_STATUS_PATH,
      expect.objectContaining({ method: "GET", credentials: "include" }),
    );
  });

  it("falls back userId to null when the 200 body carries no user_id field", async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse(200, {}));

    const status = await fetchSessionStatus(fetchMock);

    expect(status).toEqual({ authenticated: true, userId: null });
  });

  it("reports unauthenticated on 401 without throwing", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 401 }));

    const status = await fetchSessionStatus(fetchMock);

    expect(status).toEqual({ authenticated: false, userId: null });
  });

  it("throws on an unexpected status so callers do not mistake it for a clean 401", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 500 }));

    await expect(fetchSessionStatus(fetchMock)).rejects.toThrow(/500/);
  });

  it("does not send a CSRF header — session status is a safe GET", async () => {
    document.cookie = `${CSRF_COOKIE_NAME}=some-token`;
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse(200, { user_id: "user-1" }));

    await fetchSessionStatus(fetchMock);

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const headers = new Headers(init.headers);
    expect(headers.has(CSRF_HEADER_NAME)).toBe(false);
  });
});

describe("logout", () => {
  it("echoes the csrf cookie's value into the X-CSRF-Token header", async () => {
    // This is the read/write roundtrip the double-submit pattern depends
    // on: the value logout() sends MUST be byte-for-byte the value the
    // cookie carried, or the server's constant-time comparison in
    // requireCSRF (internal/api/csrf.go) rejects the request with 403.
    document.cookie = `${CSRF_COOKIE_NAME}=roundtrip-token-42`;
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));

    await logout(fetchMock);

    const [path, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(path).toBe(LOGOUT_PATH);
    expect(init.method).toBe("POST");
    expect(init.credentials).toBe("include");
    const headers = new Headers(init.headers);
    expect(headers.get(CSRF_HEADER_NAME)).toBe("roundtrip-token-42");
  });

  it("still sends the request when no csrf cookie is present, without crashing", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));

    await logout(fetchMock);

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const headers = new Headers(init.headers);
    expect(headers.has(CSRF_HEADER_NAME)).toBe(false);
  });

  it("throws when the server rejects the logout", async () => {
    document.cookie = `${CSRF_COOKIE_NAME}=some-token`;
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 403 }));

    await expect(logout(fetchMock)).rejects.toThrow(/403/);
  });
});
