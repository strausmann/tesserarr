"use client";

import { useEffect, useState, type FormEvent } from "react";
import { fetchAccount, type Account } from "@/lib/account";
import { createToken, fetchTokens, revokeToken, type ApiToken } from "@/lib/tokens";

type LoadState = { kind: "loading" } | { kind: "error"; message: string } | { kind: "ready" };

// The account view: greeting (falls back to a generic heading rather than
// ever showing a broken "Hello, " when display_name is blank), the
// signed-in visitor's profile (display name, email, role — entirely
// read-only, see internal/api/account.go's AccountPath comment for why
// email has no edit form here: it arrives exclusively from a trusted
// source, an OIDC provider's claims or a forward-auth guard's header), and
// API-token management — create (the raw value is shown exactly once,
// right here, see justCreatedToken below), list, revoke with an inline
// confirmation rather than a modal (ADR-0011: this component is the only
// place in the whole interface that needs one, so the fixed-focus/keyboard
// handling a modal requires is not worth building for it). Backed by
// internal/api/account.go and internal/api/tokens.go; built exclusively
// from the Werkstatt vocabulary in globals.css (ADR-0011) — no invented
// colour, no invented class.
export default function AccountPage() {
  const [state, setState] = useState<LoadState>({ kind: "loading" });
  const [account, setAccount] = useState<Account | null>(null);
  const [tokens, setTokens] = useState<ApiToken[]>([]);

  const [newLabel, setNewLabel] = useState("");
  const [createError, setCreateError] = useState<string | null>(null);
  // Set only by THIS render tree's own createToken() response, and never
  // written anywhere durable (no storage, no field the list endpoint
  // returns) — a reload starts a brand new component with this back at
  // null, which is what "shown exactly once" actually rests on. See
  // page.test.tsx "does not resurrect the raw token after a reload".
  const [justCreatedToken, setJustCreatedToken] = useState<string | null>(null);

  const [pendingRevoke, setPendingRevoke] = useState<string | null>(null);
  const [revokeError, setRevokeError] = useState<string | null>(null);

  // Same cancelled-guard shape as CatalogPage's session check
  // (src/app/page.tsx): without it, a slow request that resolves after the
  // visitor has already navigated away would still call setState on a
  // component nobody is looking at anymore.
  useEffect(() => {
    let cancelled = false;

    Promise.all([fetchAccount(), fetchTokens()])
      .then(([acct, toks]) => {
        if (cancelled) return;
        setAccount(acct);
        setTokens(toks);
        setState({ kind: "ready" });
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
  }, []);

  async function handleCreateToken(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const label = newLabel.trim();
    if (!label) return;

    setCreateError(null);
    try {
      const created = await createToken(label);
      setJustCreatedToken(created.token);
      setNewLabel("");
      setTokens(await fetchTokens());
    } catch (err) {
      setCreateError(err instanceof Error ? err.message : "Could not create token.");
    }
  }

  function requestRevoke(hash: string) {
    setRevokeError(null);
    setPendingRevoke(hash);
  }

  function cancelRevoke() {
    setPendingRevoke(null);
  }

  async function confirmRevoke(hash: string) {
    try {
      await revokeToken(hash);
      setTokens((prev) => prev.filter((t) => t.hash !== hash));
      setPendingRevoke(null);
    } catch (err) {
      setRevokeError(err instanceof Error ? err.message : "Could not revoke token.");
    }
  }

  if (state.kind === "loading") {
    return <p className="muted">Loading your account…</p>;
  }

  if (state.kind === "error") {
    // Still needs its own level-one heading (axe's page-has-heading-one,
    // ADR-0006's hard gate) — an unauthenticated visit lands here directly
    // rather than redirecting first (unlike CatalogPage's session check),
    // so this state is the one design-system.spec.ts actually exercises
    // for "/account/" in a suite that never completes a login.
    return (
      <div className="section">
        <h1>Your account</h1>
        <p className="form-error" role="alert">
          Could not load your account: {state.message}
        </p>
      </div>
    );
  }

  const greeting = account?.displayName.trim() ? `Hello, ${account.displayName}` : "Your account";

  return (
    <>
      <div className="section">
        <h1>{greeting}</h1>
        <p className="muted">Role: {account?.role || "none assigned yet"}</p>
        <p className="muted">Email: {account?.email || "none on file yet"}</p>
      </div>

      <div className="section">
        <span className="section__label">API tokens</span>
        <p className="muted">
          Personal tokens for Prowlarr, Sonarr and Radarr — each is shown in full exactly once,
          right here.
        </p>

        {justCreatedToken && (
          <div className="callout">
            <p className="callout__head">New token — copy it now, it will not be shown again</p>
            <code className="callout__code">{justCreatedToken}</code>
          </div>
        )}

        <form className="field" onSubmit={handleCreateToken}>
          <label className="field__label" htmlFor="new-token-label">
            Label
          </label>
          <div className="field__row">
            <input
              id="new-token-label"
              className="field__input"
              type="text"
              value={newLabel}
              onChange={(e) => setNewLabel(e.target.value)}
              placeholder="e.g. sonarr"
            />
            <button className="btn btn--primary" type="submit">
              Create token
            </button>
          </div>
        </form>
        {createError && (
          <p className="form-error" role="alert">
            {createError}
          </p>
        )}

        {tokens.length === 0 ? (
          <p className="empty">No API tokens yet. Create one above for each service that needs one.</p>
        ) : (
          <div>
            {tokens.map((t) => (
              <div className="row" key={t.hash}>
                <span>
                  <span className="row__name">{t.label}</span>{" "}
                  <span className="row__meta">
                    created {t.createdAt}
                    {t.expiresAt ? `, expires ${t.expiresAt}` : ""}
                  </span>
                </span>
                {pendingRevoke === t.hash ? (
                  <span>
                    <span className="muted">Revoke {t.label}? </span>
                    <button className="btn btn--secondary" type="button" onClick={cancelRevoke}>
                      Cancel
                    </button>{" "}
                    <button
                      className="btn btn--danger"
                      type="button"
                      onClick={() => confirmRevoke(t.hash)}
                    >
                      Revoke
                    </button>
                  </span>
                ) : (
                  <button
                    className="btn btn--danger"
                    type="button"
                    onClick={() => requestRevoke(t.hash)}
                  >
                    Revoke
                  </button>
                )}
              </div>
            ))}
          </div>
        )}
        {revokeError && (
          <p className="form-error" role="alert">
            {revokeError}
          </p>
        )}
      </div>
    </>
  );
}
