// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect } from '@playwright/test';
import type { BrowserContext, Locator, Page } from '@playwright/test';

// The on-screen keyboard makes room in one of two ways (CONTRIBUTING.md, "Web
// UI: the on-screen keyboard and inputs"), so every case runs in both:
//   - resized: the layout viewport itself gets short (Chromium with
//     `interactive-widget=resizes-content`);
//   - covered: the layout viewport keeps its size and only
//     `window.visualViewport` shrinks (iOS Safari). Headless browsers cannot
//     do that, so `installVisualViewport` puts a stand-in in its place that a
//     test shrinks like a keyboard would: same properties, same events.
// After Labelyard e2e/short-viewport.spec.ts.

export type KeyboardMode = {
    name: string;
    kind: 'resized' | 'covered';
    width: number;
    height: number;
    /** Covered only: the height left above the keyboard. */
    visible?: number;
};

export const keyboardModes: KeyboardMode[] = [
    {
        name: 'resized, tablet 1280x500',
        kind: 'resized',
        width: 1280,
        height: 500,
    },
    {
        name: 'resized, phone 390x400',
        kind: 'resized',
        width: 390,
        height: 400,
    },
    {
        name: 'covered, tablet 1280x800, 420 visible',
        kind: 'covered',
        width: 1280,
        height: 800,
        visible: 420,
    },
    {
        name: 'covered, phone 390x800, 380 visible',
        kind: 'covered',
        width: 390,
        height: 800,
        visible: 380,
    },
];

type KeyboardApi = {
    __keyboard(height: number | null, offsetTop?: number): void;
    __pan(offsetTop: number): void;
};

/** Replaces window.visualViewport with a stand-in; without a call it follows the window like the real one. */
export async function installVisualViewport(
    context: BrowserContext,
): Promise<void> {
    await context.addInitScript(() => {
        const real = window.visualViewport;
        const target = new EventTarget();
        let height: number | null = null;
        let top = 0;
        const stand = {
            get height() {
                return height ?? real?.height ?? window.innerHeight;
            },
            get offsetTop() {
                return top;
            },
            offsetLeft: 0,
            pageTop: 0,
            pageLeft: 0,
            scale: 1,
            get width() {
                return window.innerWidth;
            },
            addEventListener: target.addEventListener.bind(target),
            removeEventListener: target.removeEventListener.bind(target),
            dispatchEvent: target.dispatchEvent.bind(target),
        };
        Object.defineProperty(window, 'visualViewport', {
            configurable: true,
            value: stand,
        });
        window.addEventListener('resize', () =>
            target.dispatchEvent(new Event('resize')),
        );
        const api = window as unknown as KeyboardApi;
        // The keyboard opens: the visual viewport gets shorter (and, as on iOS,
        // may sit lower in the layout viewport).
        api.__keyboard = (h, offsetTop = 0) => {
            height = h;
            top = offsetTop;
            target.dispatchEvent(new Event('resize'));
        };
        // The browser scrolls the visual viewport inside the layout viewport:
        // only a `scroll` event, no `resize`.
        api.__pan = (offsetTop) => {
            top = offsetTop;
            target.dispatchEvent(new Event('scroll'));
        };
    });
}

/** Sets the screen of the mode with the keyboard closed. */
export async function prepareKeyboard(
    page: Page,
    mode: KeyboardMode,
): Promise<void> {
    await page.evaluate(() =>
        (window as unknown as KeyboardApi).__keyboard?.(null),
    );
    await page.setViewportSize({ width: mode.width, height: mode.height });
}

/** Opens the keyboard: a no-op when resized (the window is already short), shrinks the visual viewport when covered. */
export async function openKeyboard(
    page: Page,
    mode: KeyboardMode,
): Promise<void> {
    if (mode.kind === 'covered') {
        await page.evaluate(
            (h) => (window as unknown as KeyboardApi).__keyboard(h),
            mode.visible!,
        );
    }
}

/** What the person sees: the visual viewport, in layout viewport coordinates. */
const visibleArea = (page: Page) =>
    page.evaluate(() => ({
        top: window.visualViewport!.offsetTop,
        bottom:
            window.visualViewport!.offsetTop + window.visualViewport!.height,
    }));

/** Asserts that the element lies inside the visible area, above the keyboard. */
export async function expectInSight(
    what: Locator,
    name = what.toString(),
): Promise<void> {
    const page = what.page();
    await expect(what, `${name} is visible`).toBeVisible();
    const box = await what.boundingBox();
    const view = await visibleArea(page);
    expect(box, `${name} has a box`).not.toBeNull();
    expect(
        box!.y,
        `${name} starts below the top of the visible area (${view.top})`,
    ).toBeGreaterThanOrEqual(view.top - 1);
    expect(
        box!.y + box!.height,
        `${name} ends above the keyboard (${view.bottom})`,
    ).toBeLessThanOrEqual(view.bottom + 1);
}
