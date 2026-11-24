// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { serveFixtures } from './support/fixtures';
import { signInAs } from './support/gate';
import {
    expectInSight,
    installVisualViewport,
    keyboardModes,
    openKeyboard,
    prepareKeyboard,
} from './support/keyboard';
import type { Role } from './support/views';

// With the on-screen keyboard open, the focused field, its first option and
// the main buttons of a dialog stay in sight (CONTRIBUTING.md, "Web UI: the
// on-screen keyboard and inputs"). Every dialog, search and combobox adds a
// case to keyboardCases; each case runs in every mode of keyboardModes. A real
// device check on Android/Chrome and iPhone/Safari stays part of the release
// acceptance.

type InSight = { field: Locator; firstOption?: Locator; buttons: Locator[] };

type KeyboardCase = {
    name: string;
    path: string;
    as: Role;
    /** Opens the interface, puts the focus into the field and types; returns what must stay in sight. */
    focus(page: Page): Promise<InSight>;
};

export const keyboardCases: KeyboardCase[] = [
    {
        // The reference layout: shows that the cases fail when the visible-area
        // logic is wrong. The Tesserarr interfaces add theirs from task 7 on.
        name: 'reference dialog with a combobox (fixture)',
        path: '/_fixtures/keyboard-dialog.html',
        as: 'guest',
        focus: async (page) => {
            await page.getByRole('button', { name: 'Add share' }).click();
            const dialog = page.getByRole('dialog', { name: 'Add share' });
            const field = dialog.getByRole('combobox', { name: 'Folder' });
            await expect(field).toBeFocused();
            await page.keyboard.type('s');

            return {
                field,
                firstOption: dialog.getByRole('option').first(),
                buttons: [
                    dialog.getByRole('button', { name: 'Cancel' }),
                    dialog.getByRole('button', { name: 'Save' }),
                ],
            };
        },
    },
];

test.describe('on-screen keyboard', () => {
    test.beforeEach(async ({ context, page }) => {
        await installVisualViewport(context);
        await serveFixtures(page);
    });

    for (const kase of keyboardCases) {
        for (const mode of keyboardModes) {
            test(`${kase.name}, ${mode.name}: field, first option and main buttons are in sight`, async ({
                page,
            }) => {
                await signInAs(page, kase.as);
                await page.goto(kase.path);
                await prepareKeyboard(page, mode);
                const seen = await kase.focus(page);
                await openKeyboard(page, mode);

                await expectInSight(seen.field, 'the focused field');
                if (seen.firstOption) {
                    await expectInSight(seen.firstOption, 'the first option');
                }
                for (const [i, button] of seen.buttons.entries()) {
                    await expectInSight(button, `main button ${i + 1}`);
                }
            });
        }
    }
});
