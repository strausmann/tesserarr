// Shared implementations of the six blocking checks from
// .claude/skills/ui-verification/ (homelab-management repo) — see that
// skill's references/check-definitions.md for the reasoning behind each
// one. The seventh check (pixel screenshot) is intentionally NOT here: it
// carries no assertion, so there is nothing to share beyond
// page.screenshot() itself, called directly in each spec.
import type { Page, ConsoleMessage, Request } from "@playwright/test";
import { expect } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

// Chrome logs its OWN synthetic message to the console for every resource
// load that gets a non-2xx HTTP response — "Failed to load resource: the
// server responded with a status of 401 (Unauthorized)" — regardless of
// whether the page's own JavaScript ever calls console.error, and
// regardless of whether that response was expected and handled correctly.
// Found live on the catalog view: its session-status fetch always gets a
// 401 for an unauthenticated visitor (see src/lib/api.ts,
// fetchSessionStatus — a 401 is explicitly not an error there, it is how
// "nobody is logged in" is reported), and the check below failed on it
// even though nothing in this app's code logged anything.
//
// This is the same "expected non-2xx is not a failure" distinction
// check-definitions.md, section 3 already draws for requestfailed vs.
// response status — it turns out to apply to the console-error check too,
// through a completely different channel (the browser's own network-layer
// logging, not application code). Filtering this ONE, precisely-matched
// message pattern preserves the check's actual purpose: it still fails on
// any REAL console.error() call the application makes, and on any
// genuinely broken resource reference (an <img> pointing at a path that
// really does not exist would produce this exact message too, and remains
// caught) — none of the requests this app ever issues are that. What is
// filtered here is narrowly "the browser told us about an HTTP status",
// not "something on the page misbehaved".
const BROWSER_SYNTHETIC_RESOURCE_ERROR = /^Failed to load resource: the server responded with a status of \d+/;

/**
 * Attaches console/pageerror/requestfailed listeners BEFORE navigation, so
 * nothing fired during the initial load is missed. Call this first, then
 * navigate, then call assertClean() once the page has settled.
 *
 * requestfailed — not response status — is what "no failed network
 * requests" checks. See check-definitions.md, section 3: a 401 on the
 * catalog page's session-status request for an unauthenticated visitor is
 * expected, correctly-handled application behavior, not a network
 * failure, and requestfailed structurally never fires for it — only for a
 * real transport-level failure (DNS, connection, timeout, abort).
 */
export function watchPage(page: Page) {
  const consoleErrors: string[] = [];
  const pageErrors: string[] = [];
  const failedRequests: string[] = [];

  page.on("console", (msg: ConsoleMessage) => {
    if (msg.type() !== "error") return;
    if (BROWSER_SYNTHETIC_RESOURCE_ERROR.test(msg.text())) return;
    consoleErrors.push(msg.text());
  });
  page.on("pageerror", (err: Error) => {
    pageErrors.push(err.message);
  });
  page.on("requestfailed", (req: Request) => {
    failedRequests.push(`${req.method()} ${req.url()}: ${req.failure()?.errorText}`);
  });

  return {
    /** Check 2 + Check 3: zero console errors, zero unhandled exceptions, zero request-level failures. */
    assertClean() {
      expect(consoleErrors, "unexpected console errors").toEqual([]);
      expect(pageErrors, "unhandled exceptions").toEqual([]);
      expect(failedRequests, "requests that failed at the transport level").toEqual([]);
    },
  };
}

/** Check 4: no horizontal scroll at the page's current viewport width. */
export async function assertNoHorizontalScroll(page: Page) {
  const { scrollWidth, clientWidth } = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  // documentElement, not body — see check-definitions.md, section 4, for
  // why body's own CSS can make that comparison lie. +1px tolerance for
  // scrollbar rounding.
  expect(scrollWidth, "horizontal scroll present").toBeLessThanOrEqual(clientWidth + 1);
}

/**
 * Check 5: at least one visible focus indicator exists on the page. Tabs
 * through up to `attempts` focus stops looking for one, rather than
 * checking only the first — a page's first focusable element passing
 * proves nothing about the rest of it.
 */
export async function assertVisibleFocusExists(page: Page, attempts = 6) {
  let sawVisibleFocus = false;

  for (let i = 0; i < attempts; i++) {
    await page.keyboard.press("Tab");
    const hasVisibleFocus = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el === document.body) return false;
      const style = getComputedStyle(el);
      return style.outlineStyle !== "none" || style.boxShadow !== "none";
    });
    if (hasVisibleFocus) {
      sawVisibleFocus = true;
      break;
    }
  }

  expect(sawVisibleFocus, `no visible focus indicator found within ${attempts} Tab presses`).toBe(true);
}

/**
 * Check 6: WCAG AA color-contrast, and only that rule — see
 * check-definitions.md, section 6, for why the axe run is scoped to
 * wcag2aa rather than axe's full default rule set.
 */
export async function assertContrastPasses(page: Page) {
  const results = await new AxeBuilder({ page }).withTags(["wcag2aa"]).analyze();
  const contrastViolations = results.violations.filter((v) => v.id === "color-contrast");
  expect(contrastViolations, JSON.stringify(contrastViolations, null, 2)).toEqual([]);
}
