"use client";

import { useState } from "react";
import { logout } from "@/lib/api";

type LogoutState = "idle" | "pending" | "done" | "error";

// A confirmation view, not an auto-trigger-on-load one: logout() is a
// state-changing POST (see api.ts / ADR-0007), and nothing about merely
// navigating to this page — a bookmark, a link preview crawler, the
// browser's own prefetch — should be able to end a session as a side
// effect. The session only actually ends when handleConfirm runs, i.e. on
// an explicit click.
export default function LogoutPage() {
  const [state, setState] = useState<LogoutState>("idle");
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  async function handleConfirm() {
    setState("pending");
    setErrorMessage(null);
    try {
      await logout();
      setState("done");
    } catch (err) {
      setState("error");
      setErrorMessage(err instanceof Error ? err.message : "unknown error");
    }
  }

  if (state === "done") {
    return (
      <div className="section">
        <h1>You are logged out</h1>
        <p className="muted">Your session on this device has ended.</p>
        <a className="btn btn--primary" href="/login/">
          Back to sign in
        </a>
      </div>
    );
  }

  return (
    <div className="section">
      <h1>Log out?</h1>
      <p className="muted">This ends your current Tesserarr session on this device.</p>
      {state === "error" && (
        <p className="form-error" role="alert">
          Could not log out: {errorMessage}
        </p>
      )}
      <button
        className="btn btn--primary"
        type="button"
        onClick={handleConfirm}
        disabled={state === "pending"}
      >
        {state === "pending" ? "Logging out…" : "Confirm log out"}
      </button>
    </div>
  );
}
