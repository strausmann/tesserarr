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

type FocusStop = { tag: string; name: string; ring: boolean };

/**
 * The most tab stops the gate walks. A view with more stops than this is
 * either too dense to work through with a keyboard or traps the focus; the
 * gate fails on it instead of checking only a sample.
 */
export const FOCUS_STOPS = 30;

/**
 * Walks the tab order from the start of the page and reports each stop:
 * whether the focused element shows a ring, that is the browser's own ring
 * (`outline-style: auto`), an opaque outline of at least 2 px, or a
 * box-shadow that only the focused state has (the kit draws its rings with
 * box-shadow and also has a resting shadow). Walks until the focus leaves the
 * page or comes back to the first stop (`complete`), at most FOCUS_STOPS.
 */
async function walkFocus(
    page: Page,
): Promise<{ tabbable: number; stops: FocusStop[]; complete: boolean }> {
    // A group of radio buttons is one tab stop (the checked one, or the
    // first while none is checked); the arrow keys move inside it (HTML).
    const tabbable = await page.evaluate(() => {
        const groups = new Set<string>();

        return [
            ...document.querySelectorAll<HTMLElement>(
                'a[href], button, input, select, textarea, summary, [tabindex]',
            ),
        ].filter((el) => {
            if (
                el.tabIndex < 0 ||
                (el as HTMLButtonElement).disabled ||
                el.getClientRects().length === 0 ||
                el.closest('[inert]')
            ) {
                return false;
            }
            if (
                el instanceof HTMLInputElement &&
                el.type === 'radio' &&
                el.name !== ''
            ) {
                const group = `${el.form?.id ?? ''}|${el.name}`;
                if (groups.has(group)) {
                    return false;
                }
                groups.add(group);
            }

            return true;
        }).length;
    });
    await page.evaluate(() =>
        (document.activeElement as HTMLElement | null)?.blur(),
    );
    const stops: FocusStop[] = [];
    let first: string | null = null;
    let complete = false;
    for (let i = 0; i <= FOCUS_STOPS; i++) {
        await page.keyboard.press('Tab');
        const stop = await page.evaluate(async () => {
            const el = document.activeElement as HTMLElement | null;
            if (!el || el === document.body) {
                return null;
            }
            el.dataset.gateStop ??= String(Math.random());
            const look = () => {
                const s = getComputedStyle(el);
                return {
                    outline: s.outlineStyle,
                    width: parseFloat(s.outlineWidth),
                    color: s.outlineColor,
                    shadow: s.boxShadow,
                };
            };
            // A ring that fades in (`transition-shadow`) is measured once its
            // transition has finished, not in its first frame.
            const settled = () =>
                Promise.all(
                    el
                        .getAnimations()
                        .map((a) => a.finished.catch(() => undefined)),
                );
            await settled();
            const focused = look();
            el.blur();
            await settled();
            const resting = look();
            // Back onto the element, so the next Tab continues from here.
            el.focus({
                preventScroll: true,
                focusVisible: true,
            } as FocusOptions);
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
                id: el.dataset.gateStop,
                tag: el.tagName.toLowerCase(),
                name,
                ring: outlined || shadowed,
            };
        });
        if (!stop || stop.id === first) {
            complete = true;
            break;
        }
        if (stops.length === FOCUS_STOPS) {
            break;
        }
        first ??= stop.id;
        stops.push({ tag: stop.tag, name: stop.name, ring: stop.ring });
    }

    return { tabbable, stops, complete };
}

/** Resource types whose 4xx answer breaks the page; 4xx on fetch/XHR can be correct behaviour. */
const PAGE_RESOURCES = new Set([
    'document',
    'stylesheet',
    'script',
    'image',
    'font',
    'media',
]);

/**
 * The UI gate of ADR-0006 for one view, one viewport and one theme. Renders the
 * view and requires: every heading and control of the artboard, no horizontal
 * scrolling, a visible focus ring on every tab stop (at most FOCUS_STOPS),
 * no serious or critical axe violation, no failed request (DNS, connection,
 * abort), no 5xx answer, no 4xx answer for the document or a stylesheet,
 * script, image, font or media file (4xx on fetch/XHR is allowed), and no
 * console error or uncaught exception. A screenshot is attached to the
 * report; it is not compared with anything.
 */
export async function assertUiGate(
    page: Page,
    view: Pick<GatedView, 'name' | 'path' | 'as' | 'expect'>,
    viewport: Viewport,
    theme: Theme,
): Promise<void> {
    const consoleErrors: string[] = [];
    const failedRequests: string[] = [];
    const badAnswers: string[] = [];
    // The browser repeats every HTTP error answer as a console error. Those
    // answers are judged by `onResponse` below, so the repeat is not counted
    // twice; every other console error is.
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
    const onResponse = (response: {
        status(): number;
        url(): string;
        request(): { resourceType(): string };
    }) => {
        const status = response.status();
        const type = response.request().resourceType();
        if (status >= 500 || (status >= 400 && PAGE_RESOURCES.has(type))) {
            badAnswers.push(`${status} ${type} ${response.url()}`);
        }
    };

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
    page.on('response', onResponse);
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

        const focus = await walkFocus(page);
        expect(
            focus.complete,
            `Tab comes back to the start within ${FOCUS_STOPS} stops (walked ${focus.stops.length}; more stops or a focus trap)`,
        ).toBe(true);
        expect(
            focus.stops.length,
            `Tab reaches each of the ${focus.tabbable} tabbable elements`,
        ).toBeGreaterThanOrEqual(focus.tabbable);
        expect(
            focus.tabbable,
            'the page has a tabbable element',
        ).toBeGreaterThan(0);
        const ringless = focus.stops
            .filter((s) => !s.ring)
            .map((s) => `${s.tag} "${s.name}"`);
        expect(ringless, 'every tab stop shows a focus ring').toEqual([]);

        const axe = await new AxeBuilder({ page }).analyze();
        const severe = axe.violations
            .filter((v) => v.impact === 'serious' || v.impact === 'critical')
            .map((v) => `${v.impact}: ${v.id} (${v.nodes.length}×) ${v.help}`);
        expect(
            severe,
            'no serious or critical accessibility violation',
        ).toEqual([]);

        // Answers and requests first: the browser also logs them as console
        // errors, and they name the cause. An error answer first, because the
        // browser may then also abort the request (a 404 page is no stylesheet).
        expect(
            badAnswers,
            'no 5xx answer and no 4xx answer for a page resource',
        ).toEqual([]);
        expect(failedRequests, 'no failed request').toEqual([]);
        expect(consoleErrors, 'no console error').toEqual([]);
    } finally {
        page.off('console', onConsole);
        page.off('pageerror', onPageError);
        page.off('requestfailed', onRequestFailed);
        page.off('response', onResponse);
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
