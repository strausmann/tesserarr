import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";
import path from "node:path";

// Mirrors tsconfig.json's "@/*" -> "./src/*" path alias. Vitest runs on
// Vite, which does not read tsconfig "paths" on its own — this is the one
// other place that mapping has to be repeated, or every "@/lib/..." import
// in a *.test.tsx file would fail to resolve under test while still
// resolving fine under `next build`.
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "src"),
    },
  },
  test: {
    environment: "jsdom",
    setupFiles: ["./tests/setup.ts"],
    include: ["src/**/*.test.{ts,tsx}"],
    coverage: {
      provider: "v8",
      include: ["src/**/*.{ts,tsx}"],
      // layout.tsx has no state and no branches — the rule's own exemption
      // for "reine Init/Marker" (.claude/rules/test-coverage-pflicht.md,
      // "Frontend-JS-Pflicht") for files with nothing to exercise beyond
      // rendering fixed markup.
      exclude: ["src/**/*.test.{ts,tsx}", "src/app/layout.tsx"],
      // The rule's per-category floors (state persistence 85%, form
      // handling 80%, pure helpers 75% — .claude/rules/
      // test-coverage-pflicht.md, "Frontend-JS-Pflicht") are all comfortably
      // cleared by the actual suite (100% statements/functions/lines,
      // 97.67% branches as of the last full run — the one remaining
      // uncovered branch is csrf.ts's `typeof document === "undefined"`
      // SSR guard, which jsdom cannot exercise without deleting the global
      // document object out from under every other test in the file).
      // Thresholds are set close to that reality rather than to the rule's
      // bare minimum, so the gate actually catches a regression instead of
      // leaving 15-25 points of slack unused.
      thresholds: {
        lines: 95,
        functions: 95,
        branches: 90,
        statements: 95,
      },
    },
  },
});
