import type { Metadata } from "next";
import type { ReactNode } from "react";
import "./globals.css";

export const metadata: Metadata = {
  title: "Tesserarr",
  description: "Share parts of your media library with friends over Syncthing.",
};

// A plain server component: no auth-aware chrome here on purpose. Each page
// decides for itself what to show once it has asked /api/v1/app/session
// (see src/lib/api.ts) — a shared header could only guess at that state
// before the client-side fetch resolves, and guessing wrong for a moment on
// every page load is worse than a header with nothing to get wrong.
export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="en">
      <body>
        <header className="app-header">
          <p className="app-header__title">Tesserarr</p>
        </header>
        <main className="page">{children}</main>
      </body>
    </html>
  );
}
