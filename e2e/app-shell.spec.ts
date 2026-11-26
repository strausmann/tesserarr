// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { assertArtboard } from './support/artboard';
import { menuPhone } from './support/artboards/menu-phone';
import { signInAs } from './support/gate';

// The app frame (layouts/app/AppSidebarLayout.svelte): a fixed sidebar from
// 1024 px, below a top bar with the menu button and the whole navigation in
// a drawer. Checked once here, not per view (e2e/support/artboards/spec.ts).
// German, the language of the canvas.

test.use({ locale: 'de-DE' });

const narrow = { width: 360, height: 800 };
const wide = { width: 1440, height: 900 };

const menuButton = (page: Page) =>
    page.getByRole('button', { name: 'Menü öffnen' });
const drawer = (page: Page) => page.getByRole('dialog', { name: 'Navigation' });

async function openAt(page: Page, size: { width: number; height: number }) {
    await page.setViewportSize(size);
    await signInAs(page, 'operator');
    await page.goto('/dashboard');
}

test.describe('app frame, narrow', () => {
    test.beforeEach(async ({ page }) => openAt(page, narrow));

    test('hides the sidebar and shows the hamburger at narrow width', async ({
        page,
    }) => {
        await expect(menuButton(page)).toBeVisible();
        await expect(
            page.getByRole('complementary', { name: 'Navigation' }),
        ).toBeHidden();
        await expect(
            page.getByRole('navigation', { name: 'Hauptnavigation' }),
        ).toHaveCount(0);
    });

    test('opens and closes the drawer with the hamburger and keeps aria-expanded in sync', async ({
        page,
    }) => {
        await expect(menuButton(page)).toHaveAttribute(
            'aria-expanded',
            'false',
        );
        await expect(menuButton(page)).toHaveAttribute(
            'aria-controls',
            'tr-nav-drawer',
        );
        await menuButton(page).click();
        await expect(drawer(page)).toBeVisible();
        await expect(drawer(page)).toHaveAttribute('id', 'tr-nav-drawer');
        await expect(menuButton(page)).toHaveAttribute('aria-expanded', 'true');

        await drawer(page)
            .getByRole('button', { name: 'Menü schließen' })
            .click();
        await expect(drawer(page)).toHaveCount(0);
        await expect(menuButton(page)).toHaveAttribute(
            'aria-expanded',
            'false',
        );
    });

    test('keeps focus inside the open drawer and returns it to the hamburger on close', async ({
        page,
    }) => {
        await menuButton(page).click();
        await expect(drawer(page)).toBeVisible();
        const inDrawer = () =>
            page.evaluate(() =>
                Boolean(
                    document
                        .getElementById('tr-nav-drawer')
                        ?.contains(document.activeElement),
                ),
            );
        expect(await inDrawer(), 'the focus moves into the drawer').toBe(true);

        // More stops than the drawer has, forwards and backwards.
        const stops = await drawer(page).locator('a[href], button').count();
        for (let i = 0; i < stops + 3; i++) {
            await page.keyboard.press('Tab');
            expect(await inDrawer(), `Tab ${i + 1} stays in the drawer`).toBe(
                true,
            );
        }
        for (let i = 0; i < stops + 3; i++) {
            await page.keyboard.press('Shift+Tab');
            expect(
                await inDrawer(),
                `Shift+Tab ${i + 1} stays in the drawer`,
            ).toBe(true);
        }

        await drawer(page)
            .getByRole('button', { name: 'Menü schließen' })
            .click();
        await expect(menuButton(page)).toBeFocused();
    });

    test('closes the drawer with Escape', async ({ page }) => {
        await menuButton(page).click();
        await expect(drawer(page)).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(drawer(page)).toHaveCount(0);
        await expect(menuButton(page)).toBeFocused();
    });

    test('closes the drawer with a tap beside it', async ({ page }) => {
        await menuButton(page).click();
        await expect(drawer(page)).toBeVisible();
        await page.mouse.click(narrow.width - 10, narrow.height / 2);
        await expect(drawer(page)).toHaveCount(0);
        await expect(menuButton(page)).toBeFocused();
    });

    test('shows account, sign-out and the version in the drawer', async ({
        page,
    }) => {
        await menuButton(page).click();
        const open = drawer(page);
        await expect(
            open.getByRole('link', { name: /E2E Operator.*Mein Konto/ }),
        ).toBeVisible();
        await expect(
            open.getByRole('button', { name: 'Abmelden' }),
        ).toBeVisible();
        await expect(
            open.getByText(/^Tesserarr \S+ · Über und Lizenzen$/),
        ).toBeVisible();
    });

    // The update notice comes with task 7a into the `notice` slot of
    // AppNavigation.svelte; 7a adds its own check here.

    test('shows the open menu of its artboard', async ({ page }) => {
        await menuButton(page).click();
        await expect(drawer(page)).toBeVisible();
        // Sign-out is a button, not a link as in the canvas: it posts.
        await assertArtboard(page, {
            ...menuPhone.expect,
            controls: menuPhone.expect.controls.map((c) =>
                c === 'link:Abmelden' ? 'button:Abmelden' : c,
            ),
        });
    });

    test('closes the drawer when a link is followed', async ({ page }) => {
        await menuButton(page).click();
        await drawer(page)
            .getByRole('link', { name: /Mein Konto/ })
            .click();
        await expect(page).toHaveURL(/\/settings\/profile$/);
        await expect(drawer(page)).toHaveCount(0);
    });
});

test.describe('app frame, wide', () => {
    test.beforeEach(async ({ page }) => openAt(page, wide));

    test('shows the fixed sidebar and no hamburger at wide width', async ({
        page,
    }) => {
        await expect(menuButton(page)).toBeHidden();
        const sidebar = page.getByRole('complementary', { name: 'Navigation' });
        await expect(sidebar).toBeVisible();
        await expect(
            sidebar.getByRole('navigation', { name: 'Hauptnavigation' }),
        ).toBeVisible();
        await expect(
            sidebar.getByRole('link', { name: 'Startseite' }),
        ).toHaveAttribute('aria-current', 'page');
        await expect(
            sidebar.getByRole('button', { name: 'Abmelden' }),
        ).toBeVisible();
    });
});

test.describe('language', () => {
    test('follows the browser and then the profile', async ({ page }) => {
        await openAt(page, wide);
        await expect(
            page.getByRole('heading', { name: 'Startseite', level: 1 }),
        ).toBeVisible();
        await expect(page.locator('html')).toHaveAttribute('lang', 'de');

        await page.goto('/settings/appearance');
        await Promise.all([
            page.waitForResponse((r) => r.url().endsWith('/settings/language')),
            page.getByRole('radio', { name: 'English' }).check(),
        ]);
        // The Inertia visit swaps the props; <html lang> follows without a reload.
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        await page.goto('/dashboard');
        await expect(
            page.getByRole('heading', { name: 'Home', level: 1 }),
        ).toBeVisible();

        await page.goto('/settings/appearance');
        await Promise.all([
            page.waitForResponse((r) => r.url().endsWith('/settings/language')),
            page.getByRole('radio', { name: 'As the browser says' }).check(),
        ]);
        await page.goto('/dashboard');
        await expect(
            page.getByRole('heading', { name: 'Startseite', level: 1 }),
        ).toBeVisible();
    });
});
