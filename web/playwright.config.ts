import { defineConfig, devices } from "@playwright/test";
import path from "node:path";
import os from "node:os";

// UI-verification per .claude/skills/ui-verification/ (homelab-management
// repo): seven checks, three widths (360/768/1440), both color schemes,
// over the app's own views. Six of the seven checks run as Playwright
// assertions (tests/e2e/checks.ts); the seventh — the pixel screenshot —
// is captured as a plain artifact with no assertion attached (see
// tests/e2e/*.spec.ts and the skill's own reasoning for why a pixel
// comparison is deliberately never a gate).
//
// webServer boots two processes, in order (Playwright starts the second
// only once the first is reachable): the fake OIDC discovery endpoint
// (fake-oidc-provider.mjs) first, then the real compiled Tesserarr server,
// which needs that endpoint reachable to complete its one startup network
// call (identity.NewOIDCLogin's discovery fetch). Neither /authorize nor
// /token exists on the fake provider — see its own file comment for why
// none of the four views this suite checks needs a completed login.
const repoRoot = path.resolve(__dirname, "..");
const dataDir = path.join(os.tmpdir(), "tesserarr-ui-verification-data");

const FAKE_OIDC_PORT = 9998;
const APP_PORT = 8080;
const baseURL = `http://localhost:${APP_PORT}`;

export default defineConfig({
  testDir: "./tests/e2e",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: [["list"], ["html", { open: "never", outputFolder: "playwright-report" }]],
  outputDir: "test-results",

  use: {
    baseURL,
    trace: "retain-on-failure",
  },

  webServer: [
    {
      command: `node ${path.join(__dirname, "tests/e2e/fake-oidc-provider.mjs")}`,
      url: `http://localhost:${FAKE_OIDC_PORT}/.well-known/openid-configuration`,
      reuseExistingServer: !process.env.CI,
      env: { FAKE_OIDC_PORT: String(FAKE_OIDC_PORT) },
      timeout: 10_000,
    },
    {
      // Builds the same ./cmd/tesserarr deploy/Dockerfile builds, then runs
      // it — a plain `go build` + run, not `go run`, so a stale binary from
      // an earlier run can never linger and silently test the wrong code.
      // No package.json script involved on purpose: this stays entirely
      // inside playwright.config.ts, so it never has to touch the
      // repository-root tooling package.json (out of this suite's scope).
      command: "go build -o ./tesserarr-e2e-bin ./cmd/tesserarr && ./tesserarr-e2e-bin serve",
      cwd: repoRoot,
      url: `${baseURL}/healthz`,
      reuseExistingServer: !process.env.CI,
      // 30 s was enough locally, where the Go build cache is warm, and not
      // enough on a cold CI runner: this command compiles the whole module
      // (modernc.org/sqlite included) before the server even starts. The CI
      // workflow now pre-builds in its own step, so this budget normally
      // covers startup alone — it stays generous for the cold-cache case.
      timeout: 120_000,
      env: {
        TESSERARR_BASE_URL: baseURL,
        TESSERARR_DATA_DIR: dataDir,
        TESSERARR_OIDC_ISSUER_URL: `http://localhost:${FAKE_OIDC_PORT}`,
        TESSERARR_OIDC_CLIENT_ID: "e2e-test-client",
        TESSERARR_OIDC_CLIENT_SECRET: "e2e-test-secret-not-a-real-secret",
      },
    },
  ],

  projects: [
    { name: "mobile-light", use: { ...devices["Desktop Chrome"], viewport: { width: 360, height: 800 }, colorScheme: "light" } },
    { name: "mobile-dark", use: { ...devices["Desktop Chrome"], viewport: { width: 360, height: 800 }, colorScheme: "dark" } },
    { name: "tablet-light", use: { ...devices["Desktop Chrome"], viewport: { width: 768, height: 1024 }, colorScheme: "light" } },
    { name: "tablet-dark", use: { ...devices["Desktop Chrome"], viewport: { width: 768, height: 1024 }, colorScheme: "dark" } },
    { name: "desktop-light", use: { ...devices["Desktop Chrome"], viewport: { width: 1440, height: 900 }, colorScheme: "light" } },
    { name: "desktop-dark", use: { ...devices["Desktop Chrome"], viewport: { width: 1440, height: 900 }, colorScheme: "dark" } },
  ],
});
