// UI-verification per .claude/skills/ui-verification/ (homelab-management
// repo): the six blocking checks (render, console, network, horizontal
// scroll, keyboard focus, contrast) plus the screenshot artifact, run
// against every view this 0.0.1 scaffold has. Each Playwright project
// (playwright.config.ts) supplies one width x color-scheme combination, so
// running this file across all projects covers the full matrix without any
// manual width/theme loop in here.
import { test, expect } from "@playwright/test";
import {
  watchPage,
  assertNoHorizontalScroll,
  assertVisibleFocusExists,
  assertContrastPasses,
} from "./checks";

interface View {
  name: string;
  path: string;
  /** Renders check (1): something characteristic of this view must appear. */
  assertRendered: (page: import("@playwright/test").Page) => Promise<void>;
  /** Expected final HTTP status of the navigation itself. */
  expectedStatus: number;
}

const views: View[] = [
  {
    // No session exists in this suite (see fake-oidc-provider.mjs's own
    // comment: no login round trip is completed) — an unauthenticated
    // visit to "/" always redirects client-side to "/login/". That
    // redirect landing correctly is exactly what this view verifies; the
    // authenticated catalog content itself is out of reach without a real
    // login and is NOT covered here (see the report to team-lead).
    name: "catalog (redirects to login when unauthenticated)",
    path: "/",
    expectedStatus: 200,
    async assertRendered(page) {
      await expect(page).toHaveURL(/\/login\/$/);
      await expect(page.getByRole("heading", { name: /sign in/i })).toBeVisible();
    },
  },
  {
    name: "login",
    path: "/login/",
    expectedStatus: 200,
    async assertRendered(page) {
      await expect(page.getByRole("heading", { name: /sign in/i })).toBeVisible();
      await expect(page.getByRole("link", { name: /continue to sign in/i })).toBeVisible();
    },
  },
  {
    name: "logout",
    path: "/logout/",
    expectedStatus: 200,
    async assertRendered(page) {
      await expect(page.getByRole("heading", { name: /log out\?/i })).toBeVisible();
      await expect(page.getByRole("button", { name: /confirm log out/i })).toBeVisible();
    },
  },
  {
    // Deliberately not a real route. Confirms internal/api/static.go's own
    // 404 handling (serves the interface's compiled 404.html at a real 404
    // status, not the catalog page's index.html at 200 — see that file's
    // comment for why the distinction matters for a multi-page static
    // export like this one).
    name: "unknown path (404)",
    path: "/this-view-does-not-exist/",
    expectedStatus: 404,
    async assertRendered(page) {
      await expect(page.getByRole("heading", { name: /page not found/i })).toBeVisible();
      await expect(page.getByRole("link", { name: /back to tesserarr/i })).toBeVisible();
    },
  },
];

for (const view of views) {
  test.describe(view.name, () => {
    test("renders, no console/network errors, no horizontal scroll, visible focus, passes contrast", async ({
      page,
    }, testInfo) => {
      const watcher = watchPage(page);

      const response = await page.goto(view.path);
      expect(response?.status(), `HTTP status for ${view.path}`).toBe(view.expectedStatus);

      // Check 1: renders.
      await view.assertRendered(page);

      // Check 7 (artifact, no assertion): capture before the checks below
      // run any keyboard interaction that could shift focus visibly.
      await page.screenshot({
        path: testInfo.outputPath(`screenshot.png`),
        fullPage: true,
      });

      // Checks 2 + 3.
      watcher.assertClean();

      // Check 4.
      await assertNoHorizontalScroll(page);

      // Check 5.
      await assertVisibleFocusExists(page);

      // Check 6.
      await assertContrastPasses(page);
    });
  });
}
