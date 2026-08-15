import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { CSRF_COOKIE_NAME } from "./csrf";
import { CSRF_HEADER_NAME } from "./api";
import { fetchAccount, updateAccountEmail, ACCOUNT_PATH } from "./account";

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

describe("fetchAccount", () => {
  it("maps the response body to camelCase fields", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(
        jsonResponse(200, { display_name: "Friend", email: "friend@example.org", role: "familie" }),
      );

    const account = await fetchAccount(fetchMock);

    expect(account).toEqual({ displayName: "Friend", email: "friend@example.org", role: "familie" });
    expect(fetchMock).toHaveBeenCalledWith(
      ACCOUNT_PATH,
      expect.objectContaining({ method: "GET", credentials: "include" }),
    );
  });

  it("throws on a non-2xx status", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 401 }));

    await expect(fetchAccount(fetchMock)).rejects.toThrow(/401/);
  });
});

describe("updateAccountEmail", () => {
  it("sends the email as JSON and echoes the CSRF cookie into the header", async () => {
    document.cookie = `${CSRF_COOKIE_NAME}=roundtrip-token`;
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));

    await updateAccountEmail("me@example.org", fetchMock);

    const [path, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(path).toBe(ACCOUNT_PATH);
    expect(init.method).toBe("PATCH");
    expect(JSON.parse(init.body as string)).toEqual({ email: "me@example.org" });
    const headers = new Headers(init.headers);
    expect(headers.get(CSRF_HEADER_NAME)).toBe("roundtrip-token");
  });

  it("still sends the request when no csrf cookie is present, without crashing", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));

    await updateAccountEmail("me@example.org", fetchMock);

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const headers = new Headers(init.headers);
    expect(headers.has(CSRF_HEADER_NAME)).toBe(false);
  });

  it("throws when the server rejects the update", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 403 }));

    await expect(updateAccountEmail("me@example.org", fetchMock)).rejects.toThrow(/403/);
  });
});
