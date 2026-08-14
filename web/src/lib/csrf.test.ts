import { afterEach, describe, expect, it } from "vitest";
import { CSRF_COOKIE_NAME, readCsrfToken } from "./csrf";

// Clears every cookie jsdom's document.cookie is currently holding, so one
// test's cookie never leaks into the next.
function clearCookies() {
  document.cookie.split(";").forEach((entry) => {
    const name = entry.split("=")[0]?.trim();
    if (!name) return;
    document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
  });
}

afterEach(() => {
  clearCookies();
});

describe("readCsrfToken", () => {
  it("returns null when no cookies are set at all", () => {
    expect(readCsrfToken()).toBeNull();
  });

  it("returns null when the csrf cookie specifically is absent", () => {
    document.cookie = "tesserarr_session=some-other-value";
    expect(readCsrfToken()).toBeNull();
  });

  it("reads the value when the csrf cookie is the only cookie", () => {
    document.cookie = `${CSRF_COOKIE_NAME}=abc123`;
    expect(readCsrfToken()).toBe("abc123");
  });

  // The double-submit cookie (internal/api/csrf.go, csrfCookieName) is never
  // the only cookie on a logged-in page — the session cookie sits alongside
  // it. A parser that only handles a single cookie would pass the case
  // above and still be broken for every real request.
  it("finds the csrf cookie when it is NOT the first cookie in the jar", () => {
    document.cookie = "tesserarr_session=session-value";
    document.cookie = `${CSRF_COOKIE_NAME}=abc123`;
    expect(readCsrfToken()).toBe("abc123");
  });

  it("finds the csrf cookie when it is NOT the last cookie in the jar", () => {
    document.cookie = `${CSRF_COOKIE_NAME}=abc123`;
    document.cookie = "unrelated_cookie=zzz";
    expect(readCsrfToken()).toBe("abc123");
  });

  // A naive substring or startsWith match would wrongly pick this cookie up
  // as the csrf token because its name starts with the same characters.
  it("does not match a cookie whose name merely starts with the csrf cookie's name", () => {
    document.cookie = `${CSRF_COOKIE_NAME}_decoy=wrong-value`;
    expect(readCsrfToken()).toBeNull();
  });

  it("treats an empty cookie value the same as an absent cookie", () => {
    // internal/api/csrf.go's requireCSRF rejects an empty cookie value the
    // same way it rejects a missing cookie (cookie.Value == "") — the
    // frontend's read side has to agree, or a request could look "covered"
    // here while the server would reject it anyway.
    document.cookie = `${CSRF_COOKIE_NAME}=`;
    expect(readCsrfToken()).toBeNull();
  });

  it("trims surrounding whitespace produced by the '; ' cookie jar format", () => {
    // Browsers join document.cookie entries with "; " (space after the
    // semicolon). Splitting on ";" alone leaves that leading space on every
    // entry but the first — the parser must not let it become part of the
    // name or the value.
    document.cookie = "a=1";
    document.cookie = `${CSRF_COOKIE_NAME}=abc123`;
    document.cookie = "b=2";
    expect(document.cookie).toContain("; ");
    expect(readCsrfToken()).toBe("abc123");
  });

  it("percent-decodes a value that was percent-encoded when set", () => {
    document.cookie = `${CSRF_COOKIE_NAME}=${encodeURIComponent("has spaces")}`;
    expect(readCsrfToken()).toBe("has spaces");
  });

  it("round-trips a real base64url token unchanged", () => {
    // generateCSRFToken (internal/api/csrf.go) encodes with
    // base64.RawURLEncoding — an alphabet of A-Z, a-z, 0-9, '-', '_' that
    // never needs percent-encoding. The read side must hand this back
    // byte-for-byte, not accidentally mangle it while decoding.
    const realisticToken = "3fH9-kLm_Q7vN2sT8pXeY4wR6bC1dGz0aJ5uI";
    document.cookie = `${CSRF_COOKIE_NAME}=${realisticToken}`;
    expect(readCsrfToken()).toBe(realisticToken);
  });

  it("accepts an explicit cookie string instead of reading document.cookie", () => {
    // Lets callers (and the tests above) avoid depending on jsdom's exact
    // document.cookie semantics for cases where only the parsing logic is
    // under test.
    const jar = `foo=bar; ${CSRF_COOKIE_NAME}=explicit-value; baz=qux`;
    expect(readCsrfToken(jar)).toBe("explicit-value");
  });

  it("skips a malformed entry with no '=' at all instead of throwing", () => {
    const jar = `just-a-flag-no-equals; ${CSRF_COOKIE_NAME}=abc123`;
    expect(readCsrfToken(jar)).toBe("abc123");
  });

  it("falls back to the raw value when the value is not valid percent-encoding", () => {
    // "%" alone is not a complete escape sequence — decodeURIComponent
    // throws a URIError on it. The cookie can never have come from
    // ensureCSRFCookie (server-generated, always base64url) at that point,
    // but a value THIS malformed is still safer to hand back as-is than to
    // treat as "no cookie": the server's own comparison will simply reject
    // it as wrong, the same outcome as any other bad token.
    const jar = `${CSRF_COOKIE_NAME}=100%`;
    expect(readCsrfToken(jar)).toBe("100%");
  });
});
