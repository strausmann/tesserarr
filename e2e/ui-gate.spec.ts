// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { assertArtboard, assertArtboardStates } from './support/artboard';
import { serveFixtures } from './support/fixtures';
import { assertUiGate, signInAs } from './support/gate';
import { gatedViews, gateViewports, themes } from './support/views';
import type { GatedView } from './support/views';

// The UI gate (ADR-0006): every view of gatedViews at every viewport in both
// themes, plus the states of its artboard. The self-tests first: they show
// that the gate fails for the reason it names, on pages built to fail.

const fixtureView = (
    file: string,
): Pick<GatedView, 'name' | 'path' | 'as' | 'expect'> => ({
    name: file,
    path: `/_fixtures/${file}`,
    as: 'guest',
    expect: {
        headings: ['Gate fixture'],
        controls: ['button:Continue'],
        states: [],
    },
});

const phone = { width: 360, height: 800 };

test.describe('the gate itself', () => {
    test.beforeEach(async ({ page }) => serveFixtures(page));

    test('passes on a page without defects', async ({ page }) => {
        await assertUiGate(page, fixtureView('clean.html'), phone, 'light');
    });

    test('fails on a horizontally scrolling page', async ({ page }) => {
        await expect(
            assertUiGate(page, fixtureView('overflow.html'), phone, 'light'),
        ).rejects.toThrow(
            /no horizontal scrolling: content 20\d\dpx wide in a 360px viewport/,
        );
    });

    test('fails when an artboard control is missing', async ({ page }) => {
        const login = gatedViews.find((v) => v.name === 'Login')!;
        await page.goto(login.path);

        await expect(
            assertArtboard(page, {
                ...login.expect,
                controls: [...login.expect.controls, 'button:Sign up'],
            }),
        ).rejects.toThrow(/artboard control "button:Sign up" is shown/);
    });
});

for (const view of gatedViews) {
    test.describe(`${view.name} (${view.artboard})`, () => {
        test('shows each state of its artboard', async ({ page }) => {
            await signInAs(page, view.as);
            await assertArtboardStates(page, view);
        });

        for (const viewport of gateViewports) {
            for (const theme of themes) {
                test(`passes the gate at ${viewport.name}, ${theme}`, async ({
                    page,
                }) => {
                    await assertUiGate(page, view, viewport, theme);
                });
            }
        }
    });
}
