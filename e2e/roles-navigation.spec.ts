// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { signInAs } from './support/gate';

// The navigation follows the permissions the Authorizer grants (task 8,
// pre-flight K6): a friend sees their catalogue, tokens and account and no
// administration, an operator the administration; a page the account may
// not open answers 403, not only a missing link.

test.use({ locale: 'de-DE', viewport: { width: 1440, height: 900 } });

const main = (page: import('@playwright/test').Page) =>
    page
        .getByRole('complementary', { name: 'Navigation' })
        .getByRole('navigation', { name: 'Hauptnavigation' });

test('a friend gets the friend navigation and no administration', async ({
    page,
}) => {
    await signInAs(page, 'friend');
    await page.goto('/shared');

    await expect(
        main(page).getByRole('link', { name: 'Katalog' }),
    ).toBeVisible();
    for (const admin of ['Nutzer', 'Rollen und Rechte', 'Protokoll']) {
        await expect(main(page).getByRole('link', { name: admin })).toHaveCount(
            0,
        );
    }

    const refused = await page.goto('/users');
    expect(refused?.status()).toBe(403);
});

test('an operator gets the administration', async ({ page }) => {
    await signInAs(page, 'operator');
    await page.goto('/dashboard');

    for (const admin of ['Nutzer', 'Rollen und Rechte', 'Protokoll']) {
        await expect(
            main(page).getByRole('link', { name: admin }),
        ).toBeVisible();
    }
    await main(page).getByRole('link', { name: 'Protokoll' }).click();
    await expect(
        page.getByRole('heading', { name: 'Protokoll', level: 1 }),
    ).toBeVisible();
});
