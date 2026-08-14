import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

// Reads the real stylesheet rather than a copy of the values: a test that
// restated the numbers would pass forever while the stylesheet drifted.
const css = readFileSync(join(__dirname, "globals.css"), "utf8");

/** The first `:root { ... }` block — the light mode. */
function lightBlock(source: string): string {
  const start = source.indexOf(":root {");
  if (start === -1) throw new Error("no :root block found");
  const end = source.indexOf("}", start);
  return source.slice(start, end);
}

/** The `:root { ... }` nested inside the dark media query. */
function darkBlock(source: string): string {
  const media = source.indexOf("@media (prefers-color-scheme: dark)");
  if (media === -1) throw new Error("no dark media query found");
  const start = source.indexOf(":root {", media);
  const end = source.indexOf("}", start);
  return source.slice(start, end);
}

function token(block: string, name: string): string {
  const match = block.match(new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{6})`));
  if (!match) throw new Error(`token --${name} not found or not a 6-digit hex`);
  // Non-null: match[1] is the capture group, guaranteed present by the regex
  // above once `match` itself is non-null. Needed under noUncheckedIndexedAccess.
  return match[1]!;
}

function channel(value: number): number {
  const c = value / 255;
  return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
}

function luminance(hex: string): number {
  const n = hex.replace("#", "");
  // Cast: three fixed indices (0, 2, 4) into a 6-character hex string always
  // produce three elements. Needed under noUncheckedIndexedAccess.
  const [r, g, b] = [0, 2, 4].map((i) => parseInt(n.slice(i, i + 2), 16)) as [
    number,
    number,
    number,
  ];
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

function contrast(a: string, b: string): number {
  const [la, lb] = [luminance(a), luminance(b)];
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

const modes = [
  { name: "light", block: lightBlock(css) },
  { name: "dark", block: darkBlock(css) },
];

// 4.5 for text (WCAG 1.4.3), 3.0 for user interface components (1.4.11).
const pairs: Array<{ what: string; fg: string; bg: string; min: number }> = [
  { what: "body text on the page", fg: "color-fg", bg: "color-bg", min: 4.5 },
  { what: "body text on a surface", fg: "color-fg", bg: "color-surface", min: 4.5 },
  { what: "muted text on the page", fg: "color-fg-muted", bg: "color-bg", min: 4.5 },
  { what: "muted text on a surface", fg: "color-fg-muted", bg: "color-surface", min: 4.5 },
  { what: "label on the accent fill", fg: "color-accent-fg", bg: "color-accent", min: 4.5 },
  { what: "accent as text", fg: "color-accent", bg: "color-bg", min: 4.5 },
  { what: "ok state", fg: "color-ok", bg: "color-bg", min: 4.5 },
  { what: "warn state", fg: "color-warn", bg: "color-bg", min: 4.5 },
  { what: "danger state", fg: "color-danger", bg: "color-bg", min: 4.5 },
  // The input border IS what identifies a field, so 1.4.11 applies to it.
  { what: "input border on the page", fg: "color-input-border", bg: "color-bg", min: 3 },
  { what: "input border on a surface", fg: "color-input-border", bg: "color-surface", min: 3 },
  // outline-offset places the ring outside the element, so these two are the
  // pairs that decide whether it is visible — never ring-against-accent.
  { what: "focus ring on the page", fg: "color-focus-ring", bg: "color-bg", min: 3 },
  { what: "focus ring on a surface", fg: "color-focus-ring", bg: "color-surface", min: 3 },
];

describe.each(modes)("$name mode contrast", ({ block }) => {
  it.each(pairs)("$what reaches $min:1", ({ fg, bg, min }) => {
    expect(contrast(token(block, fg), token(block, bg))).toBeGreaterThanOrEqual(min);
  });
});

describe("the focus ring's precondition", () => {
  it("keeps outline-offset above zero", () => {
    const match = css.match(/outline-offset:\s*([\d.]+)px/);
    expect(match, "no outline-offset declared").not.toBeNull();
    expect(Number(match![1])).toBeGreaterThan(0);
  });
});
