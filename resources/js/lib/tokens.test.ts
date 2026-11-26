// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/// <reference types="node" />
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

// The colours of the artboard "Stil & Logo" (Main.dc.html) of the approved
// 0.1.0 mockup canvas, which are the Labelyard tokens. A change here is a
// design decision: it needs a new approval in docs/designs/.
const canvasLight: Record<string, string> = {
    bg: '#f6f4ef',
    surface: '#fdfcf9',
    sunken: '#efebe3',
    line: '#e3ded3',
    'line-strong': '#cfc8ba',
    ink: '#1f1d1a',
    'ink-2': '#4a463f',
    'ink-3': '#6b655a',
    accent: '#d4912a',
    'accent-ink': '#2b1d06',
    'accent-soft': '#f6e6c8',
    link: '#2f4e8f',
    ok: '#2e7a4e',
    warn: '#b5651b',
    bad: '#b0352b',
};

const canvasDark: Record<string, string> = {
    bg: '#171614',
    surface: '#201e1b',
    sunken: '#29261f',
    line: '#35312a',
    'line-strong': '#4a443a',
    ink: '#f1eee7',
    'ink-2': '#c4beb2',
    'ink-3': '#958e81',
    accent: '#e2a445',
    'accent-ink': '#241703',
    'accent-soft': '#3a2d16',
    link: '#8faee6',
    ok: '#6cc58f',
    warn: '#e39a4f',
    bad: '#ee8579',
};

// Read as text: Vitest does not load CSS (a `?raw` import comes back empty).
// Vitest runs from the repository root (vitest.config.ts).
const css = readFileSync(`${process.cwd()}/resources/css/tokens.css`, 'utf8');

/** The declarations `--tr-*: value` of one rule block, e.g. `:root` or `.dark`. */
function block(selector: string): Map<string, string> {
    const start = css.indexOf(`\n${selector} {`);
    expect(start, `tokens.css has a block "${selector}"`).toBeGreaterThan(-1);
    const body = css.slice(start, css.indexOf('\n}', start));
    const vars = new Map<string, string>();
    for (const m of body.matchAll(/--tr-([a-z0-9-]+):\s*([^;]+);/g)) {
        vars.set(m[1], m[2].trim());
    }

    return vars;
}

/** Follows `var(--tr-x)` inside the same block to a literal value. */
function resolve(vars: Map<string, string>, name: string): string {
    let value = vars.get(name);
    for (let hops = 0; value?.startsWith('var(--tr-') && hops < 5; hops++) {
        value = vars.get(value.slice('var(--tr-'.length, -1));
    }

    return value ?? '';
}

describe('design tokens', () => {
    const light = block(':root');
    const dark = block('.dark');

    it('has the light colours of the canvas', () => {
        for (const [role, hex] of Object.entries(canvasLight)) {
            expect(resolve(light, role), `--tr-${role} (light)`).toBe(hex);
        }
    });

    it('has the dark colours of the canvas', () => {
        for (const [role, hex] of Object.entries(canvasDark)) {
            expect(dark.get(role), `--tr-${role} (dark)`).toBe(hex);
        }
    });

    it('overrides every colour role in dark mode and nothing else', () => {
        expect([...dark.keys()].sort()).toEqual(Object.keys(canvasDark).sort());
    });

    it('has the type, radii, grid and focus ring of the canvas', () => {
        expect(light.get('font-sans')).toContain("'Atkinson Hyperlegible'");
        expect(light.get('font-mono')).toContain(
            "'Atkinson Hyperlegible Mono'",
        );
        expect(light.get('text-title')).toBe('28px');
        expect(light.get('text-section')).toBe('20px');
        expect(light.get('text-body')).toBe('15px');
        expect(light.get('text-small')).toBe('13px');
        expect(light.get('space-unit')).toBe('4px');
        expect(['r-1', 'r-2', 'r-3'].map((r) => light.get(r))).toEqual([
            '6px',
            '10px',
            '14px',
        ]);
        expect(light.get('focus-ring')).toBe('3px solid var(--tr-link)');
        expect(light.get('focus-offset')).toBe('2px');
        expect(light.get('control-height')).toBe('44px');
        expect(light.get('icon-button-hit')).toBe('44px');
    });
});
