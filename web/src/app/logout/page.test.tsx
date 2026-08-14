import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { CSRF_COOKIE_NAME } from "@/lib/csrf";
import LogoutPage from "./page";

// LogoutPage's logout button runs through the REAL logout() (src/lib/api.ts),
// stubbed at global fetch — see the comment in ../page.test.tsx for why this
// is preferred over mocking the "@/lib/api" module directly for this suite.
let fetchMock: ReturnType<typeof vi.fn>;

function clearCookies() {
  document.cookie.split(";").forEach((entry) => {
    const name = entry.split("=")[0]?.trim();
    if (!name) return;
    document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
  });
}

beforeEach(() => {
  fetchMock = vi.fn();
  vi.stubGlobal("fetch", fetchMock);
  clearCookies();
});

afterEach(() => {
  vi.unstubAllGlobals();
  clearCookies();
});

describe("LogoutPage", () => {
  it("shows a confirm button and does NOT call fetch on render alone", () => {
    render(<LogoutPage />);

    expect(
      screen.getByRole("button", { name: /confirm log out/i }),
    ).toBeInTheDocument();
    // The whole point of a confirmation view: navigating here must never by
    // itself end the session (see the component's own comment).
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("sends the csrf cookie's value as the X-CSRF-Token header and shows the confirmation", async () => {
    document.cookie = `${CSRF_COOKIE_NAME}=roundtrip-token-42`;
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }));
    const user = userEvent.setup();
    render(<LogoutPage />);

    await user.click(screen.getByRole("button", { name: /confirm log out/i }));

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [path, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(path).toBe("/api/v1/app/logout");
    expect(init.method).toBe("POST");
    expect(init.credentials).toBe("include");
    expect(new Headers(init.headers).get("X-CSRF-Token")).toBe("roundtrip-token-42");

    await waitFor(() =>
      expect(screen.getByText(/you are logged out/i)).toBeInTheDocument(),
    );
    expect(screen.getByRole("link", { name: /back to sign in/i })).toHaveAttribute(
      "href",
      "/login/",
    );
  });

  it("shows the error and re-enables the button when the server rejects the logout", async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 403 }));
    const user = userEvent.setup();
    render(<LogoutPage />);

    await user.click(screen.getByRole("button", { name: /confirm log out/i }));

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("403");

    const button = screen.getByRole("button", { name: /confirm log out/i });
    expect(button).not.toBeDisabled();
  });

  it("shows a generic message when fetch rejects with something other than an Error", async () => {
    fetchMock.mockRejectedValue("not an Error instance");
    const user = userEvent.setup();
    render(<LogoutPage />);

    await user.click(screen.getByRole("button", { name: /confirm log out/i }));

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("unknown error");
  });

  it("disables the button while the logout request is pending", async () => {
    let resolveFetch: (response: Response) => void = () => {};
    fetchMock.mockReturnValue(
      new Promise<Response>((resolve) => {
        resolveFetch = resolve;
      }),
    );
    const user = userEvent.setup();
    render(<LogoutPage />);

    await user.click(screen.getByRole("button", { name: /confirm log out/i }));

    expect(screen.getByRole("button", { name: /logging out/i })).toBeDisabled();

    resolveFetch(new Response(null, { status: 204 }));
    await waitFor(() =>
      expect(screen.getByText(/you are logged out/i)).toBeInTheDocument(),
    );
  });
});
