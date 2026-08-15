import { afterEach, describe, expect, it, vi } from "vitest";
import { fetchAccount, ACCOUNT_PATH } from "./account";

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

afterEach(() => {
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
