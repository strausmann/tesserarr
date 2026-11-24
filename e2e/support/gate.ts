// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { assertArtboard } from './artboard';
import type { GatedView, Role, Theme } from './views';

export type Viewport = { width: number; height: number };

/** Signs the browser in as the fixture account of the role (E2eLoginController). */
export async function signInAs(page: Page, role: Role): Promise<void> {
    if (role === 'guest') {
        return;
    }
    const response = await page.goto(`/_testing/login/${role}`);
    expect(response?.ok(), `sign-in as ${role} through /_testing/login`).toBe(
        true,
    );
}

type FocusReport = { tag: string; name: string; ring: boolean } | null;

/**
 * Presses Tab once and reports whether the focused element shows a ring: the
 * browser's own ring (`outline-style: auto`), an opaque outline of at least
 * 2 px, or a box-shadow that only the focused state has (the kit draws its
 * rings with box-shadow and also has a resting shadow).
 */
async function tabAndInspectFocus(page: Page): Promise<FocusReport> {
    await page.keyboard.press('Tab');

    return page.evaluate(() => {
        const el = document.activeElement as HTMLElement | null;
        if (!el || el === document.body) {
            return null;
        }
        const look = () => {
            const s = getComputedStyle(el);
            return {
                outline: s.outlineStyle,
                width: parseFloat(s.outlineWidth),
                color: s.outlineColor,
                shadow: s.boxShadow,
            };
        };
        const focused = look();
        el.blur();
        const resting = look();
        const transparent = (c: string) =>
            c === 'transparent' || /rgba\(.*,\s*0\)$/.test(c);
        // `auto` is the browser's own focus ring, drawn whatever the width says.
        const outlined =
            focused.outline === 'auto' ||
            (focused.outline !== 'none' &&
                focused.width >= 2 &&
                !transparent(focused.color));
        const shadowed =
            focused.shadow !== 'none' && focused.shadow !== resting.shadow;
        const name = (el.getAttribute('aria-label') ?? el.textContent ?? '')
            .trim()
            .slice(0, 40);

        return {
            tag: el.tagName.toLowerCase(),
            name,
            ring: outlined || shadowed,
        };
    });
}

/**
 * The UI gate of ADR-0006 for one view, one viewport and one theme. Renders the
 * view and requires: no console error and no uncaught exception, no failed
 * request (DNS, connection, abort; a 4xx/5xx answer is not a failed request),
 * no horizontal scrolling, a visible focus ring after Tab, no serious or
 * critical axe violation, and every heading and control of the artboard. A
 * screenshot is attached to the report; it is not compared with anything.
 */
export async function assertUiGate(
    page: Page,
    view: Pick<GatedView, 'name' | 'path' | 'as' | 'expect'>,
    viewport: Viewport,
    theme: Theme,
): Promise<void> {
    const consoleErrors: string[] = [];
    const failedRequests: string[] = [];
    // The browser reports every HTTP error answer as a console error. An HTTP
    // status is not a failure of the page (a 401 of a session probe is correct
    // behaviour); a request that does not complete is caught by `requestfailed`.
    const httpStatusReport =
        /^Failed to load resource: the server responded with a status of \d{3}/;
    const onConsole = (msg: { type(): string; text(): string }) => {
        if (msg.type() === 'error' && !httpStatusReport.test(msg.text())) {
            consoleErrors.push(msg.text());
        }
    };
    const onPageError = (error: Error) =>
        consoleErrors.push(`uncaught: ${error.message}`);
    const onRequestFailed = (request: {
        url(): string;
        failure(): { errorText: string } | null;
    }) =>
        failedRequests.push(
            `${request.url()} (${request.failure()?.errorText ?? 'failed'})`,
        );

    await page.setViewportSize(viewport);
    await page.emulateMedia({ colorScheme: theme });
    // Signing in is set-up, not the view under test: the gate listens only
    // once its page has settled, so a request of the sign-in redirect that the
    // next navigation aborts is not counted against the view.
    await signInAs(page, view.as);
    await page.waitForLoadState('networkidle');
    page.on('console', onConsole);
    page.on('pageerror', onPageError);
    page.on('requestfailed', onRequestFailed);
    try {
        await page.goto(view.path, { waitUntil: 'networkidle' });

        const dark = await page.evaluate(() =>
            document.documentElement.classList.contains('dark'),
        );
        expect(dark, `the page renders in the ${theme} theme`).toBe(
            theme === 'dark',
        );

        await assertArtboard(page, view.expect);

        const scroll = await page.evaluate(() => ({
            content: document.documentElement.scrollWidth,
            viewport: document.documentElement.clientWidth,
        }));
        expect(
            scroll.content,
            `no horizontal scrolling: content ${scroll.content}px wide in a ${scroll.viewport}px viewport`,
        ).toBeLessThanOrEqual(scroll.viewport);

        const focus = await tabAndInspectFocus(page);
        expect(focus, 'Tab moves the focus onto an element').not.toBeNull();
        expect(
            focus!.ring,
            `the focused ${focus!.tag} "${focus!.name}" shows a focus ring`,
        ).toBe(true);

        const axe = await new AxeBuilder({ page }).analyze();
        const severe = axe.violations
            .filter((v) => v.impact === 'serious' || v.impact === 'critical')
            .map((v) => `${v.impact}: ${v.id} (${v.nodes.length}×) ${v.help}`);
        expect(
            severe,
            'no serious or critical accessibility violation',
        ).toEqual([]);

        // Requests first: the browser also logs a failed request as a console
        // error, and the request names the cause.
        expect(failedRequests, 'no failed request').toEqual([]);
        expect(consoleErrors, 'no console error').toEqual([]);
    } finally {
        page.off('console', onConsole);
        page.off('pageerror', onPageError);
        page.off('requestfailed', onRequestFailed);
        await test
            .info()
            .attach(
                `${view.name} ${viewport.width}x${viewport.height} ${theme}`,
                {
                    body: await page.screenshot({ fullPage: true }),
                    contentType: 'image/png',
                },
            );
    }
}
