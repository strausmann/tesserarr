// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { assertArtboard } from './support/artboard';
import { signInAs } from './support/gate';

// "New version available" against two real builds (lib/updates.svelte.ts).
// scripts/e2e/run.sh starts the image under test (version A, E2E_BASE_URL)
// and, with E2E_NEXT_IMAGE, a second build with another TESSERARR_VERSION
// (version B, E2E_NEXT_URL) on the same database and APP_KEY. A test opens a
// page on A and then sends every request of that page to B: for the browser
// the server was restarted with a new version, the tab keeps the bundle of A.
// German, the language of the canvas.

test.use({ locale: 'de-DE' });

const base = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8080';
const next = process.env.E2E_NEXT_URL;

test.skip(
    !next && !process.env.CI,
    'needs the second build: scripts/e2e/run.sh with E2E_NEXT_IMAGE',
);

const HEADER = 'x-tesserarr-version';

/** The version an instance runs, from its page (not from the header under test). */
async function versionOf(page: Page, url: string): Promise<string> {
    const html = await (await page.request.get(`${url}/login`)).text();
    // Inertia 3 puts the page into <script data-page="app" type="application/json">.
    const json = /<script data-page="app"[^>]*>([\s\S]*?)<\/script>/.exec(
        html,
    )?.[1];
    const version = json
        ? (JSON.parse(json) as { version?: string }).version
        : undefined;
    expect(version, `${url} names its version in the page`).toBeTruthy();

    return version!;
}

/** Signs in on A, opens the dashboard and marks the document, which a reload would throw away. */
async function openOnA(page: Page): Promise<void> {
    await page.setViewportSize({ width: 1440, height: 900 });
    await signInAs(page, 'operator');
    await page.goto('/dashboard');
    await expect(
        page.getByRole('heading', { name: 'Startseite', level: 1 }),
    ).toBeVisible();
    await page.evaluate(() => {
        (window as unknown as { __sameDocument: boolean }).__sameDocument =
            true;
    });
}

/** From now on every request of the page reaches B: the "restart". */
async function restartAsB(page: Page): Promise<void> {
    expect(next, 'E2E_NEXT_URL is set (CI runs the second build)').toBeTruthy();
    const from = new URL(base);
    const to = new URL(next!);
    await page.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (url.host === from.host) {
            url.protocol = to.protocol;
            url.host = to.host;
        }
        if (url.host !== to.host) {
            await route.continue();

            return;
        }
        // Fetched outside the browser and handed back as the answer of A:
        // Chromium would send the cookies of B's address (none) on a
        // redirected request, so the cookies of A go along by hand, and what
        // B sets lands on A, as after a real restart.
        const cookies = await page.context().cookies(base);
        const response = await route.fetch({
            url: url.toString(),
            headers: {
                ...route.request().headers(),
                cookie: cookies.map((c) => `${c.name}=${c.value}`).join('; '),
            },
            maxRedirects: 0,
        });
        await route.fulfill({ response });
    });
}

const sameDocument = (page: Page) =>
    page.evaluate(
        () =>
            (window as unknown as { __sameDocument?: boolean })
                .__sameDocument === true,
    );
const toast = (page: Page) =>
    page.getByRole('region', { name: 'Neue Version' });
const sidebar = (page: Page) =>
    page.getByRole('complementary', { name: 'Navigation' });

test.describe('update notice', () => {
    let a = '';
    let b = '';

    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage();
        a = await versionOf(page, base);
        b = next ? await versionOf(page, next) : '';
        await page.close();
        expect(b, 'the second build runs another version').not.toBe(a);
    });

    test('names the version on pages and the session API but not on files', async ({
        page,
    }) => {
        await signInAs(page, 'operator');
        const api = await page.request.get('/api/v1/app/version');
        expect(api.headers()[HEADER]).toBe(a);
        expect(await api.json()).toEqual({ version: a });

        const file = await page.request.get('/favicon.svg');
        expect(file.ok()).toBe(true);
        expect(file.headers()[HEADER]).toBeUndefined();
        const built = await page.request.get('/build/manifest.json');
        expect(built.headers()[HEADER]).toBeUndefined();
    });

    test('shows the banner after the session API names a new version and never reloads', async ({
        page,
    }) => {
        await openOnA(page);
        await expect(toast(page)).toHaveCount(0);

        await restartAsB(page);
        // The check of a tab that becomes visible again (GET /api/v1/app/version).
        await page.evaluate(() =>
            document.dispatchEvent(new Event('visibilitychange')),
        );

        await expect(toast(page)).toBeVisible();
        await expect(toast(page)).toContainText('Neue Version verfügbar');
        await expect(toast(page)).toContainText(`Version ${b}`);
        await expect(sidebar(page)).toContainText(`Version ${b} bereit`);
        await page.waitForTimeout(1500);
        expect(await sameDocument(page)).toBe(true);

        // Later closes the toast for this version; the box stays.
        await toast(page).getByRole('button', { name: 'Später' }).click();
        await expect(toast(page)).toHaveCount(0);
        await expect(sidebar(page)).toContainText(`Version ${b} bereit`);
        // Remembered for the other tabs of the browser.
        expect(
            await page.evaluate(() =>
                window.localStorage.getItem('tesserarr.update.dismissed'),
            ),
        ).toBe(b);
        expect(await sameDocument(page)).toBe(true);
    });

    test('stays on the page when an Inertia background reload meets the new version', async ({
        page,
    }) => {
        // Inertia answers the outdated reload with 409 and a location; the
        // reload of the "About" dialog is async, so Inertia does not follow
        // it (H3). The version in the header of the 409 shows the banner.
        await openOnA(page);
        await restartAsB(page);
        const conflict = page.waitForResponse(
            (r) => r.status() === 409 && r.url().endsWith('/dashboard'),
        );

        await sidebar(page)
            .getByRole('button', { name: /Über und Lizenzen/ })
            .click();

        const answer = await conflict;
        expect(answer.headers()[HEADER]).toBe(b);
        await expect(toast(page)).toBeVisible();
        await page.waitForTimeout(1500);
        expect(await sameDocument(page)).toBe(true);
        await expect(
            page.getByRole('dialog', { name: 'Über Tesserarr' }),
        ).toBeVisible();
    });

    test('reloads into the new version when asked, and shows it in the foot and under About', async ({
        page,
    }) => {
        await openOnA(page);
        await expect(
            sidebar(page).getByRole('button', {
                name: `Tesserarr ${a} · Über und Lizenzen`,
            }),
        ).toBeVisible();
        await restartAsB(page);
        await page.evaluate(() =>
            document.dispatchEvent(new Event('visibilitychange')),
        );
        await expect(toast(page)).toBeVisible();

        await toast(page).getByRole('button', { name: 'Neu laden' }).click();
        await page.waitForLoadState('load');

        expect(await sameDocument(page)).toBe(false);
        const foot = sidebar(page).getByRole('button', {
            name: `Tesserarr ${b} · Über und Lizenzen`,
        });
        await expect(foot).toBeVisible();
        await expect(toast(page)).toHaveCount(0);
        await expect(sidebar(page)).not.toContainText('bereit');

        await foot.click();
        const about = page.getByRole('dialog', { name: 'Über Tesserarr' });
        await expect(about).toBeVisible();
        await expect(about).toContainText(b);
        // The canvas draws only the link in the foot; the dialog has no
        // artboard of its own. What it must show:
        await assertArtboard(page, {
            headings: ['Über Tesserarr'],
            controls: [
                'dialog:Über Tesserarr',
                'link:Quellcode',
                'link:Lizenztext',
            ],
            states: ['filled'],
        });
        // The release check is off by default: About says so, and the e2e
        // stack never sends anything to GitHub.
        await expect(about).toContainText(
            'Die Prüfung auf neuere Versionen ist ausgeschaltet.',
        );
        await page.keyboard.press('Escape');
        await expect(about).toHaveCount(0);
        await expect(foot).toBeFocused();
    });

    test('shows account, sign-out, version and the update notice in the drawer', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 360, height: 800 });
        await signInAs(page, 'operator');
        await page.goto('/dashboard');
        await restartAsB(page);
        await page.evaluate(() =>
            document.dispatchEvent(new Event('visibilitychange')),
        );
        await expect(toast(page)).toBeVisible();

        await page.getByRole('button', { name: 'Menü öffnen' }).click();
        const drawer = page.getByRole('dialog', { name: 'Navigation' });
        await expect(
            drawer.getByRole('link', { name: /Mein Konto/ }),
        ).toBeVisible();
        await expect(
            drawer.getByRole('button', { name: 'Abmelden' }),
        ).toBeVisible();
        await expect(drawer).toContainText(`Version ${b} bereit`);
        await expect(
            drawer.getByRole('button', { name: /Über und Lizenzen/ }),
        ).toBeVisible();

        // "About" from the drawer: the drawer closes, the focus goes back to
        // the menu button afterwards.
        await drawer.getByRole('button', { name: /Über und Lizenzen/ }).click();
        await expect(drawer).toHaveCount(0);
        await expect(
            page.getByRole('dialog', { name: 'Über Tesserarr' }),
        ).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(
            page.getByRole('button', { name: 'Menü öffnen' }),
        ).toBeFocused();
    });
});
