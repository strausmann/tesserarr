import { expect, test } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

// "/account/" fetches on mount (see src/app/account/page.tsx) and this
// suite never completes a login (fake-oidc-provider.mjs's own comment
// explains why), so it always ends up in the error state here — that is
// still a real, fully-rendered state built from the same Werkstatt
// vocabulary as every other view, and exercising it is exactly what these
// checks are for. The authenticated account/token views themselves are out
// of reach without a real login round trip (same limitation views.spec.ts
// already documents for the catalog view) and are NOT covered here.
const pages = ["/", "/login/", "/logout/", "/account/"];

// Both modes get the same checks: the dark mode is not a variant that may
// degrade, it is the mode most of this tool's users will actually see. This
// file used to spell that out itself with its own light/dark loop — but
// playwright.config.ts already runs every test in this directory across six
// projects (three widths x both color schemes), so that loop only meant each
// check ran twice as often as the project matrix alone already guarantees
// (78 tests instead of 42, mode encoded twice: once in the project name,
// once in the test title). Removed; `page` here inherits whichever
// colorScheme the running project set via `test.use`, same as every other
// spec in this directory.
//
// The two checks below (accessibility, 320px overflow) still run once per
// project — six times total, three of them genuinely redundant per page,
// since neither depends on the project's own viewport (both request their
// dimensions explicitly) or, as far as this scaffold's CSS goes, on color
// scheme. Left as-is rather than singled out to run once: doing that
// without touching playwright.config.ts would mean hardcoding a project
// name into this file as a skip condition, which is a step *more* fragile
// than the redundancy it removes (a later rename/reorder of projects in the
// config would silently stop the check from running anywhere, instead of
// just running it more than strictly necessary). The six runs are cheap
// against the local fake-OIDC + compiled-binary server this suite already
// pays to boot once per config.
for (const path of pages) {
  test(`${path} has no accessibility violations`, async ({ page }) => {
    await page.goto(path);
    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations).toEqual([]);
  });

  test(`${path} does not scroll sideways at 320px`, async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 640 });
    await page.goto(path);
    const overflows = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    expect(overflows, "the page scrolls horizontally at 320px").toBe(false);
  });
}

test("the shipped monospace is actually used and loaded", async ({ page }) => {
  // No page in this 0.0.1 scaffold has a .row__meta/.callout__code element
  // yet (the first consumer lands with the account view — Aufgabe 9 of the
  // forward-auth plan), so the browser never has a reason to fetch the face
  // on its own: document.fonts.ready alone would pass this test even with
  // the @font-face block deleted entirely, because nothing on the page asks
  // for it. So this test asks explicitly instead of waiting for a consumer
  // that does not exist yet — that is what proves the face is genuinely
  // *available*, which is the property worth guarding regardless of which
  // page ends up consuming it.
  const fontRequests: string[] = [];
  page.on("request", (req) => {
    if (req.url().endsWith("/fonts/IBMPlexMono-Regular-Latin1.woff2")) {
      fontRequests.push(req.url());
    }
  });

  await page.goto("/");

  // Asserting the declared family alone would pass even if the file 404'd,
  // so ask the browser to actually fetch it and then check whether it has
  // the face. The second argument to load() must be text inside the
  // declared unicode-range — the font is subset, and without a matching
  // sample the browser has nothing to request a glyph for and loads
  // nothing (see the unicode-range comment in globals.css).
  const loaded = await page.evaluate(async () => {
    await document.fonts.load('400 13px "IBM Plex Mono"', "abc123");
    return document.fonts.check('400 13px "IBM Plex Mono"');
  });
  expect(loaded, "IBM Plex Mono did not load").toBe(true);

  // NOT redundant with the assertion above — confirmed by counter-test
  // (commenting out the @font-face block entirely, see the report to
  // team-lead): document.fonts.check() still answered true with no
  // matching @font-face at all, presumably because Chromium's Font Loading
  // API considers the family satisfiable via fallback resolution rather
  // than strictly requiring the declared face to have loaded. Only this
  // network assertion caught that break. So this is not a second
  // independent guard on the same property — for this API, on this
  // browser, it is the one that actually holds the line.
  expect(fontRequests, "no request for the shipped font file was observed").not.toEqual([]);
});
