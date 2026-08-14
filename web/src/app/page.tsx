"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { fetchSessionStatus, type SessionStatus } from "@/lib/api";

type LoadState =
  | { kind: "loading" }
  | { kind: "error"; message: string }
  | { kind: "ready"; status: SessionStatus };

// The catalog view. Per the 0.0.1 scope (docs/superpowers/plans/
// 2026-08-13-tesserarr-0-0-1-teil-b-zugang.md, Aufgabe 7) it never shows any
// media — there is no sharing pipeline yet, only the login/session
// scaffold. What it DOES have to do for real is decide, from the actual
// session status, whether the visitor belongs here at all — an unauthenticated
// visitor is redirected to /login/ rather than shown an empty catalog that
// looks like "yes, you're in, there's just nothing here".
export default function CatalogPage() {
  const router = useRouter();
  const [state, setState] = useState<LoadState>({ kind: "loading" });

  useEffect(() => {
    let cancelled = false;

    fetchSessionStatus()
      .then((status) => {
        if (cancelled) return;
        if (!status.authenticated) {
          router.replace("/login/");
          return;
        }
        setState({ kind: "ready", status });
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setState({
          kind: "error",
          message: err instanceof Error ? err.message : "unknown error",
        });
      });

    return () => {
      cancelled = true;
    };
  }, [router]);

  if (state.kind === "loading") {
    return <p className="muted">Checking your session…</p>;
  }

  if (state.kind === "error") {
    return (
      <div className="section">
        <p className="form-error">Could not reach Tesserarr: {state.message}</p>
      </div>
    );
  }

  return (
    <div className="section">
      <div className="section">
        <h1>Your catalog</h1>
        <p className="muted">
          Your catalog is empty. Tesserarr does not move any media yet — the
          sharing pipeline (indexer, download client, browsing) is not part
          of this 0.0.1 scaffold. It lands with 0.1.0.
        </p>
      </div>
      <p>
        <a href="/logout/">Log out</a>
      </p>
    </div>
  );
}
