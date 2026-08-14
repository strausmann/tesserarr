// A custom not-found page, not Next's default. The default one renders
// text only — no link, no button, nothing focusable at all — which the
// UI-verification suite's keyboard-focus check (web/tests/e2e/,
// .claude/skills/ui-verification/ in the homelab-management repo) caught
// on its very first full run: a page with nothing to Tab to trivially
// fails "sichtbarer Tastatur-Fokus", and is also a real dead end for a
// keyboard-only visitor, not just a checkbox to satisfy. One link back to
// the catalog fixes both.
//
// A plain <a>, not next/link's <Link> — every other cross-page link in
// this app (login/page.tsx, logout/page.tsx, app/page.tsx) already makes
// that same choice deliberately: this is a static export of separately
// built, separately served pages, not a client-routed single-page app
// (see internal/api/static.go's own comment on why an unknown path gets
// the export's own 404.html rather than any page's JS taking over). Using
// <Link> here was tried first and immediately caught by the same
// UI-verification suite: it triggers Next's client-side prefetch for "/",
// which the browser aborts once the test navigates away before the
// prefetch finishes — surfacing as a genuine requestfailed event ("HEAD
// http://localhost:8080/: net::ERR_ABORTED"), not a false positive. A
// plain anchor never starts that prefetch, so there is nothing to abort.
export default function NotFound() {
  return (
    <div className="section">
      <h1>Page not found</h1>
      <p className="muted">There is nothing at this address.</p>
      <a className="btn btn--primary" href="/">
        Back to Tesserarr
      </a>
    </div>
  );
}
