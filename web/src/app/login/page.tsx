import { LOGIN_START_URL } from "@/lib/auth-links";

// A plain server component: this page carries no state of its own — it is
// one link to LOGIN_START_URL (see auth-links.ts for what that URL is and
// the assumption behind it), so there is nothing here for a unit test to
// exercise beyond "does the link point at the right place", which
// page.test.tsx covers.
export default function LoginPage() {
  return (
    <div className="card stack">
      <h1>Sign in</h1>
      <p className="muted">
        Tesserarr is invite-only. If you were invited, sign in with the
        account your host set up for you.
      </p>
      <a className="button" href={LOGIN_START_URL}>
        Continue to sign in
      </a>
    </div>
  );
}
