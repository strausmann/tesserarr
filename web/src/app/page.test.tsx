import { render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import CatalogPage from "./page";

// Only next/navigation is module-mocked here — CatalogPage's data fetching
// runs through the REAL fetchSessionStatus (src/lib/api.ts), stubbed at the
// actual I/O boundary (global fetch) instead. An earlier version of this
// file mocked the "@/lib/api" module directly and was reproducibly flaky
// ONLY when the full suite ran (never in isolation): logout/page.test.tsx
// mocks the same module specifier with a different shape, and under
// Vitest's default fork/worker reuse across files that occasionally left
// fetchSessionStatus() resolving to undefined instead of a function.
// Stubbing global.fetch avoids module-mock identity entirely and matches
// how src/lib/api.test.ts already exercises the same code reliably.
const replaceMock = vi.hoisted(() => vi.fn());

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: replaceMock }),
}));

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

let fetchMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
  fetchMock = vi.fn();
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  vi.unstubAllGlobals();
  replaceMock.mockReset();
});

describe("CatalogPage", () => {
  it("shows the empty-catalog explanation once the session is confirmed authenticated", async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { user_id: "user-1" }));

    render(<CatalogPage />);

    await waitFor(() =>
      expect(screen.getByText(/your catalog is empty/i)).toBeInTheDocument(),
    );
    expect(replaceMock).not.toHaveBeenCalled();
  });

  it("redirects an unauthenticated visitor to /login/ instead of showing the catalog", async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 401 }));

    render(<CatalogPage />);

    await waitFor(() => expect(replaceMock).toHaveBeenCalledWith("/login/"));
    expect(screen.queryByText(/your catalog is empty/i)).not.toBeInTheDocument();
  });

  it("shows an error message instead of a blank page when the status request itself fails", async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 500 }));

    render(<CatalogPage />);

    await waitFor(() =>
      expect(screen.getByText(/could not reach tesserarr/i)).toBeInTheDocument(),
    );
    expect(replaceMock).not.toHaveBeenCalled();
  });

  it("shows a loading state before the session check resolves", () => {
    fetchMock.mockReturnValue(new Promise(() => {})); // never resolves

    render(<CatalogPage />);

    expect(screen.getByText(/checking your session/i)).toBeInTheDocument();
  });

  it("does not navigate after being unmounted before a resolved fetch settles (.then guard)", async () => {
    // Exercises the `cancelled` guard in the effect's .then branch: without
    // it, a slow request that resolves after the visitor has already
    // navigated away would redirect a page nobody is looking at anymore.
    let resolveFetch: (response: Response) => void = () => {};
    fetchMock.mockReturnValue(
      new Promise<Response>((resolve) => {
        resolveFetch = resolve;
      }),
    );

    const { unmount } = render(<CatalogPage />);
    unmount();
    resolveFetch(jsonResponse(401, {}));

    // Give the (now-orphaned) .then callback a chance to run if the guard
    // were missing — it should have nothing to do.
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(replaceMock).not.toHaveBeenCalled();
  });

  it("does not throw after being unmounted before a rejected fetch settles (.catch guard)", async () => {
    // The .catch side of the same guard: a request that FAILS after unmount
    // must not try to setState on the component that is no longer there.
    let rejectFetch: (reason: unknown) => void = () => {};
    fetchMock.mockReturnValue(
      new Promise<Response>((_resolve, reject) => {
        rejectFetch = reject;
      }),
    );

    const { unmount } = render(<CatalogPage />);
    unmount();
    rejectFetch(new Error("network error after unmount"));

    // If the guard were missing, the orphaned .catch callback calling
    // setState on an unmounted component would surface as a thrown/rejected
    // error somewhere in this window — this awaits long enough for it to
    // have done so.
    await new Promise((resolve) => setTimeout(resolve, 0));
  });

  it("shows a generic message when the fetch rejects with something other than an Error", async () => {
    // fetch() itself only ever rejects with a TypeError in real browsers,
    // but fetchSessionStatus's own thrown errors are always real Error
    // instances too — this covers the defensive fallback for whatever
    // non-Error value some other rejection path could still produce.
    fetchMock.mockRejectedValue("not an Error instance");

    render(<CatalogPage />);

    await waitFor(() =>
      expect(screen.getByText(/could not reach tesserarr: unknown error/i)).toBeInTheDocument(),
    );
  });
});
