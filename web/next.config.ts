import type { NextConfig } from "next";

// ADR-0005 (docs/adr/0005-go-kern-getrennte-oberflaeche-gemeinsames-abbild.md):
// this app builds to a static export ("out/"), which the Go build then
// embeds via go:embed (see internal/web). There is no Next.js server at
// runtime, so every data access happens client-side against /api/v1 — see
// ADR-0007 for why the browser only ever holds a session cookie, never a
// provider token, and src/lib/api.ts for the actual calls.
//
// trailingSlash is set to true so each route exports as its own
// "<route>/index.html" directory rather than a bare "<route>.html" file —
// the layout a static file server conventionally expects when it falls back
// to serving "index.html" for a directory request.
const nextConfig: NextConfig = {
  output: "export",
  trailingSlash: true,
};

export default nextConfig;
