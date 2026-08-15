import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import AccountPage from "./page";

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

let fetchMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
  fetchMock = vi.fn();
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

function mockAccountAndTokens(account: object, tokens: unknown[] = []) {
  fetchMock.mockImplementation((path: string) => {
    if (path === "/api/v1/app/account") return Promise.resolve(jsonResponse(200, account));
    if (path === "/api/v1/app/tokens") return Promise.resolve(jsonResponse(200, tokens));
    return Promise.resolve(new Response(null, { status: 404 }));
  });
}

const oneToken = {
  hash: "h1",
  label: "sonarr",
  created_at: "2026-01-01T00:00:00Z",
  last_used_at: null,
  expires_at: null,
};

describe("AccountPage", () => {
  it("shows a loading state before the account/tokens fetch resolves", () => {
    fetchMock.mockReturnValue(new Promise(() => {})); // never resolves

    render(<AccountPage />);

    expect(screen.getByText(/loading your account/i)).toBeInTheDocument();
  });

  it("greets the user by display name once loaded", async () => {
    mockAccountAndTokens({ display_name: "Friend", email: "friend@example.org", role: "familie" });

    render(<AccountPage />);

    await waitFor(() => expect(screen.getByText(/hello, friend/i)).toBeInTheDocument());
  });

  it("falls back to a generic heading when display_name is blank", async () => {
    mockAccountAndTokens({ display_name: "", email: "", role: "" });

    render(<AccountPage />);

    await waitFor(() => expect(screen.getByText(/your account/i)).toBeInTheDocument());
    expect(screen.queryByText(/hello,/i)).not.toBeInTheDocument();
  });

  it("shows the role, falling back to a placeholder when none is assigned", async () => {
    mockAccountAndTokens({ display_name: "Friend", email: "", role: "" });

    render(<AccountPage />);

    await waitFor(() => expect(screen.getByText(/none assigned yet/i)).toBeInTheDocument());
  });

  it("shows an error message when loading fails", async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 500 }));

    render(<AccountPage />);

    await waitFor(() => expect(screen.getByText(/could not load your account/i)).toBeInTheDocument());
    // The error state still needs its own level-one heading (axe's
    // page-has-heading-one, ADR-0006's hard gate) — an unauthenticated
    // visit lands here directly, it never redirects first.
    expect(screen.getByRole("heading", { level: 1, name: /your account/i })).toBeInTheDocument();
  });

  it("shows a generic message when the load rejects with something other than an Error", async () => {
    fetchMock.mockRejectedValue("not an Error instance");

    render(<AccountPage />);

    await waitFor(() =>
      expect(screen.getByText(/could not load your account: unknown error/i)).toBeInTheDocument(),
    );
  });

  it("does not update state after being unmounted before the load resolves", async () => {
    let resolveAccount: (response: Response) => void = () => {};
    fetchMock.mockImplementation((path: string) => {
      if (path === "/api/v1/app/account") {
        return new Promise<Response>((resolve) => {
          resolveAccount = resolve;
        });
      }
      return Promise.resolve(jsonResponse(200, []));
    });

    const { unmount } = render(<AccountPage />);
    unmount();
    resolveAccount(jsonResponse(200, { display_name: "Friend", email: "", role: "" }));

    // If the cancelled-guard were missing, the orphaned .then callback would
    // call setState on an unmounted component — this awaits long enough for
    // it to have done so (React logs that as a warning/throw in dev mode,
    // and this test's presence is what would surface it).
    await new Promise((resolve) => setTimeout(resolve, 0));
  });

  it("does not update state after being unmounted before a rejected load settles (.catch guard)", async () => {
    // The .catch side of the same guard: a load that FAILS after unmount
    // must not try to setState on the component that is no longer there.
    let rejectAccount: (reason: unknown) => void = () => {};
    fetchMock.mockImplementation((path: string) => {
      if (path === "/api/v1/app/account") {
        return new Promise<Response>((_resolve, reject) => {
          rejectAccount = reject;
        });
      }
      return Promise.resolve(jsonResponse(200, []));
    });

    const { unmount } = render(<AccountPage />);
    unmount();
    rejectAccount(new Error("network error after unmount"));

    await new Promise((resolve) => setTimeout(resolve, 0));
  });

  it("lists existing tokens with their label", async () => {
    mockAccountAndTokens({ display_name: "Friend", email: "", role: "" }, [oneToken]);

    render(<AccountPage />);

    await waitFor(() => expect(screen.getByText(/sonarr/)).toBeInTheDocument());
  });

  it("shows the expiry date for a token that has one", async () => {
    mockAccountAndTokens(
      { display_name: "Friend", email: "", role: "" },
      [{ ...oneToken, expires_at: "2027-01-01T00:00:00Z" }],
    );

    render(<AccountPage />);

    await waitFor(() => expect(screen.getByText(/expires 2027-01-01/i)).toBeInTheDocument());
  });

  it("shows an empty state when there are no tokens yet", async () => {
    mockAccountAndTokens({ display_name: "Friend", email: "", role: "" }, []);

    render(<AccountPage />);

    await waitFor(() => expect(screen.getByText(/no api tokens yet/i)).toBeInTheDocument());
  });

  it("saves the email and shows a confirmation", async () => {
    const user = userEvent.setup();
    mockAccountAndTokens({ display_name: "Friend", email: "old@example.org", role: "" });
    render(<AccountPage />);
    await waitFor(() => expect(screen.getByDisplayValue("old@example.org")).toBeInTheDocument());

    fetchMock.mockImplementation((path: string, init?: RequestInit) => {
      if (path === "/api/v1/app/account" && init?.method === "PATCH") {
        return Promise.resolve(new Response(null, { status: 204 }));
      }
      if (path === "/api/v1/app/account") return Promise.resolve(jsonResponse(200, { display_name: "Friend", email: "old@example.org", role: "" }));
      return Promise.resolve(jsonResponse(200, []));
    });

    const emailInput = screen.getByLabelText(/email address/i);
    await user.clear(emailInput);
    await user.type(emailInput, "new@example.org");
    await user.click(screen.getByRole("button", { name: /^save$/i }));

    await waitFor(() => expect(screen.getByText(/saved/i)).toBeInTheDocument());
  });

  it("shows an error when saving the email fails", async () => {
    const user = userEvent.setup();
    mockAccountAndTokens({ display_name: "Friend", email: "old@example.org", role: "" });
    render(<AccountPage />);
    await waitFor(() => expect(screen.getByDisplayValue("old@example.org")).toBeInTheDocument());

    fetchMock.mockImplementation((path: string, init?: RequestInit) => {
      if (path === "/api/v1/app/account" && init?.method === "PATCH") {
        return Promise.resolve(new Response(null, { status: 403 }));
      }
      return Promise.resolve(jsonResponse(200, { display_name: "Friend", email: "old@example.org", role: "" }));
    });

    await user.click(screen.getByRole("button", { name: /^save$/i }));

    await waitFor(() => expect(screen.getByText(/could not save email|403/i)).toBeInTheDocument());
  });

  it("shows a generic message when saving the email rejects with something other than an Error", async () => {
    // Covers the ": 'Could not save email.'" fallback branch — fetch()
    // itself only rejects with a TypeError in real browsers, but this
    // exercises the defensive fallback the same way page.test.tsx does for
    // CatalogPage's equivalent branch.
    const user = userEvent.setup();
    mockAccountAndTokens({ display_name: "Friend", email: "old@example.org", role: "" });
    render(<AccountPage />);
    await waitFor(() => expect(screen.getByDisplayValue("old@example.org")).toBeInTheDocument());

    fetchMock.mockImplementation((path: string, init?: RequestInit) => {
      if (path === "/api/v1/app/account" && init?.method === "PATCH") {
        return Promise.reject("not an Error instance");
      }
      return Promise.resolve(jsonResponse(200, { display_name: "Friend", email: "old@example.org", role: "" }));
    });

    await user.click(screen.getByRole("button", { name: /^save$/i }));

    await waitFor(() => expect(screen.getByText(/could not save email\./i)).toBeInTheDocument());
  });

  it("creates a token, shows it exactly once in a callout, and lists it afterwards", async () => {
    const user = userEvent.setup();
    mockAccountAndTokens({ display_name: "Friend", email: "", role: "" }, []);
    render(<AccountPage />);
    await waitFor(() => expect(screen.getByText(/no api tokens yet/i)).toBeInTheDocument());

    fetchMock.mockImplementation((path: string, init?: RequestInit) => {
      if (path === "/api/v1/app/tokens" && init?.method === "POST") {
        return Promise.resolve(
          jsonResponse(201, {
            token: "raw-secret-abc123",
            hash: "h1",
            label: "sonarr",
            created_at: "2026-01-01T00:00:00Z",
            last_used_at: null,
            expires_at: null,
          }),
        );
      }
      if (path === "/api/v1/app/tokens") return Promise.resolve(jsonResponse(200, [oneToken]));
      return Promise.resolve(jsonResponse(200, { display_name: "Friend", email: "", role: "" }));
    });

    await user.type(screen.getByLabelText(/^label$/i), "sonarr");
    await user.click(screen.getByRole("button", { name: /create token/i }));

    await waitFor(() => expect(screen.getByText("raw-secret-abc123")).toBeInTheDocument());
    // The list refresh (fetchTokens) landed too, and the row for it is visible.
    await waitFor(() => expect(screen.getAllByText(/sonarr/i).length).toBeGreaterThan(1));
  });

  it("does not resurrect the raw token after a reload (fresh mount)", async () => {
    const user = userEvent.setup();
    mockAccountAndTokens({ display_name: "Friend", email: "", role: "" }, []);
    const { unmount } = render(<AccountPage />);
    await waitFor(() => expect(screen.getByText(/no api tokens yet/i)).toBeInTheDocument());

    fetchMock.mockImplementation((path: string, init?: RequestInit) => {
      if (path === "/api/v1/app/tokens" && init?.method === "POST") {
        return Promise.resolve(
          jsonResponse(201, {
            token: "raw-secret-once-only",
            hash: "h1",
            label: "sonarr",
            created_at: "2026-01-01T00:00:00Z",
            last_used_at: null,
            expires_at: null,
          }),
        );
      }
      if (path === "/api/v1/app/tokens") return Promise.resolve(jsonResponse(200, [oneToken]));
      return Promise.resolve(jsonResponse(200, { display_name: "Friend", email: "", role: "" }));
    });

    await user.type(screen.getByLabelText(/^label$/i), "sonarr");
    await user.click(screen.getByRole("button", { name: /create token/i }));
    await waitFor(() => expect(screen.getByText("raw-secret-once-only")).toBeInTheDocument());

    // "Reload": unmount the page entirely and mount a fresh instance — this
    // is what a real navigation reload does, a brand new component with no
    // memory of the previous one's state. Only the list endpoint (which
    // never carries the raw value, see tokens.ts) backs the new mount.
    unmount();
    mockAccountAndTokens({ display_name: "Friend", email: "", role: "" }, [oneToken]);
    render(<AccountPage />);

    await waitFor(() => expect(screen.getByText(/sonarr/)).toBeInTheDocument());
    expect(screen.queryByText("raw-secret-once-only")).not.toBeInTheDocument();
  });

  it("does not create a token for a blank label", async () => {
    const user = userEvent.setup();
    mockAccountAndTokens({ display_name: "Friend", email: "", role: "" }, []);
    render(<AccountPage />);
    await waitFor(() => expect(screen.getByText(/no api tokens yet/i)).toBeInTheDocument());

    const callsBefore = fetchMock.mock.calls.length;
    await user.click(screen.getByRole("button", { name: /create token/i }));

    expect(fetchMock.mock.calls.length).toBe(callsBefore);
  });

  it("shows an error when token creation fails", async () => {
    const user = userEvent.setup();
    mockAccountAndTokens({ display_name: "Friend", email: "", role: "" }, []);
    render(<AccountPage />);
    await waitFor(() => expect(screen.getByText(/no api tokens yet/i)).toBeInTheDocument());

    fetchMock.mockImplementation((path: string, init?: RequestInit) => {
      if (path === "/api/v1/app/tokens" && init?.method === "POST") {
        return Promise.resolve(new Response(null, { status: 400 }));
      }
      if (path === "/api/v1/app/tokens") return Promise.resolve(jsonResponse(200, []));
      return Promise.resolve(jsonResponse(200, { display_name: "Friend", email: "", role: "" }));
    });

    await user.type(screen.getByLabelText(/^label$/i), "sonarr");
    await user.click(screen.getByRole("button", { name: /create token/i }));

    await waitFor(() => expect(screen.getByText(/could not create token|400/i)).toBeInTheDocument());
  });

  it("shows a generic message when token creation rejects with something other than an Error", async () => {
    const user = userEvent.setup();
    mockAccountAndTokens({ display_name: "Friend", email: "", role: "" }, []);
    render(<AccountPage />);
    await waitFor(() => expect(screen.getByText(/no api tokens yet/i)).toBeInTheDocument());

    fetchMock.mockImplementation((path: string, init?: RequestInit) => {
      if (path === "/api/v1/app/tokens" && init?.method === "POST") {
        return Promise.reject("not an Error instance");
      }
      if (path === "/api/v1/app/tokens") return Promise.resolve(jsonResponse(200, []));
      return Promise.resolve(jsonResponse(200, { display_name: "Friend", email: "", role: "" }));
    });

    await user.type(screen.getByLabelText(/^label$/i), "sonarr");
    await user.click(screen.getByRole("button", { name: /create token/i }));

    await waitFor(() => expect(screen.getByText(/could not create token\./i)).toBeInTheDocument());
  });

  it("asks for confirmation before revoking, and Cancel leaves the token intact", async () => {
    const user = userEvent.setup();
    mockAccountAndTokens({ display_name: "Friend", email: "", role: "" }, [oneToken]);
    render(<AccountPage />);
    await waitFor(() => expect(screen.getByText(/sonarr/)).toBeInTheDocument());

    await user.click(screen.getByRole("button", { name: /revoke/i }));
    expect(screen.getByText(/revoke sonarr\?/i)).toBeInTheDocument();

    const revokeCallsBefore = fetchMock.mock.calls.filter(
      (call) => call[1]?.method === "DELETE",
    ).length;
    await user.click(screen.getByRole("button", { name: /^cancel$/i }));

    expect(screen.queryByText(/revoke sonarr\?/i)).not.toBeInTheDocument();
    expect(screen.getByText(/sonarr/)).toBeInTheDocument();
    const revokeCallsAfter = fetchMock.mock.calls.filter(
      (call) => call[1]?.method === "DELETE",
    ).length;
    expect(revokeCallsAfter).toBe(revokeCallsBefore);
  });

  it("revokes a token after confirmation and removes it from the list", async () => {
    const user = userEvent.setup();
    mockAccountAndTokens({ display_name: "Friend", email: "", role: "" }, [oneToken]);
    render(<AccountPage />);
    await waitFor(() => expect(screen.getByText(/sonarr/)).toBeInTheDocument());

    fetchMock.mockImplementation((path: string, init?: RequestInit) => {
      if (path === `/api/v1/app/tokens/h1` && init?.method === "DELETE") {
        return Promise.resolve(new Response(null, { status: 204 }));
      }
      return Promise.resolve(jsonResponse(200, { display_name: "Friend", email: "", role: "" }));
    });

    await user.click(screen.getByRole("button", { name: /revoke/i }));
    await user.click(screen.getByRole("button", { name: /^revoke$/i }));

    await waitFor(() => expect(screen.getByText(/no api tokens yet/i)).toBeInTheDocument());
  });

  it("shows an error and keeps the token when revocation fails", async () => {
    const user = userEvent.setup();
    mockAccountAndTokens({ display_name: "Friend", email: "", role: "" }, [oneToken]);
    render(<AccountPage />);
    await waitFor(() => expect(screen.getByText(/sonarr/)).toBeInTheDocument());

    fetchMock.mockImplementation((path: string, init?: RequestInit) => {
      if (path === `/api/v1/app/tokens/h1` && init?.method === "DELETE") {
        return Promise.resolve(new Response(null, { status: 500 }));
      }
      return Promise.resolve(jsonResponse(200, { display_name: "Friend", email: "", role: "" }));
    });

    await user.click(screen.getByRole("button", { name: /revoke/i }));
    await user.click(screen.getByRole("button", { name: /^revoke$/i }));

    await waitFor(() => expect(screen.getByText(/could not revoke token|500/i)).toBeInTheDocument());
    // Scoped to .row__name: the still-open "Revoke sonarr?" confirmation
    // text also contains the substring "sonarr", so an unscoped query here
    // would be ambiguous between the two.
    expect(screen.getByText("sonarr", { selector: ".row__name" })).toBeInTheDocument();
  });

  it("shows a generic message when revocation rejects with something other than an Error", async () => {
    const user = userEvent.setup();
    mockAccountAndTokens({ display_name: "Friend", email: "", role: "" }, [oneToken]);
    render(<AccountPage />);
    await waitFor(() => expect(screen.getByText(/sonarr/)).toBeInTheDocument());

    fetchMock.mockImplementation((path: string, init?: RequestInit) => {
      if (path === `/api/v1/app/tokens/h1` && init?.method === "DELETE") {
        return Promise.reject("not an Error instance");
      }
      return Promise.resolve(jsonResponse(200, { display_name: "Friend", email: "", role: "" }));
    });

    await user.click(screen.getByRole("button", { name: /revoke/i }));
    await user.click(screen.getByRole("button", { name: /^revoke$/i }));

    await waitFor(() => expect(screen.getByText(/could not revoke token\./i)).toBeInTheDocument());
  });
});
