import "@testing-library/jest-dom/vitest";
import { afterEach } from "vitest";
import { cleanup } from "@testing-library/react";

afterEach(() => {
  // Unmounts anything rendered by the last test, so event listeners and
  // pending timers from one test cannot leak into the next.
  cleanup();

  // Cookies set with document.cookie in one test are otherwise still
  // visible to the next test in the same jsdom document — every csrf.ts /
  // api.ts test that sets a cookie relies on this being cleared first.
  document.cookie.split(";").forEach((entry) => {
    const name = entry.split("=")[0]?.trim();
    if (!name) return;
    document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
  });
});
