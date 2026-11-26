// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import {
    assertArtboard,
    assertArtboardStates,
    stateFixtures,
} from './support/artboard';
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

    // One defect per fixture; each test names the rule that must catch it.
    const broken: Array<[string, string, RegExp]> = [
        [
            'logs a console error',
            'console-error.html',
            /no console error[\s\S]*broken fixture/,
        ],
        [
            'has a tab stop without a focus ring',
            'no-focus-ring.html',
            /every tab stop shows a focus ring[\s\S]*button \\?"Continue/,
        ],
        [
            'has a serious or critical axe violation',
            'axe-violation.html',
            /no serious or critical accessibility violation[\s\S]*critical: button-name/,
        ],
        [
            'loads from a host that does not resolve',
            'failed-request.html',
            /no failed request[\s\S]*unreachable\.invalid\/pixel\.png \(net::ERR_NAME_NOT_RESOLVED\)/,
        ],
        [
            'has a sixth tab stop without a focus ring',
            'late-no-ring.html',
            /every tab stop shows a focus ring[\s\S]*Sixth/,
        ],
        [
            'has more tab stops than the gate walks',
            'too-many-stops.html',
            /Tab comes back to the start within 30 stops \(walked 30/,
        ],
        [
            'loads an image that answers 404',
            'missing-image.html',
            /no 5xx answer and no 4xx answer for a page resource[\s\S]*404 image \S*\/_fixtures\/missing\.png/,
        ],
        [
            'gets a 500 answer to a fetch',
            'server-error.html',
            /no 5xx answer and no 4xx answer for a page resource[\s\S]*500 fetch \S*\/_fixtures\/server-error/,
        ],
        [
            'loads a stylesheet that answers 404',
            'missing-stylesheet.html',
            /no 5xx answer and no 4xx answer for a page resource[\s\S]*404 stylesheet \S*\/_fixtures\/missing\.css/,
        ],
    ];
    for (const [defect, file, reason] of broken) {
        test(`fails on a page that ${defect}`, async ({ page }) => {
            await expect(
                assertUiGate(page, fixtureView(file), phone, 'light'),
            ).rejects.toThrow(reason);
        });
    }

    test('fails when a state of the view loses an artboard control', async ({
        page,
    }) => {
        const view = {
            ...fixtureView('state-breaks-artboard.html'),
            expect: {
                headings: ['Gate fixture'],
                controls: ['button:Continue'],
                states: ['filled' as const],
            },
        };
        stateFixtures[view.name] = {
            filled: async (p) => {
                await p.getByRole('button', { name: 'Continue' }).click();
                await expect(p.getByText('Done')).toBeVisible();
            },
        };

        await expect(assertArtboardStates(page, view)).rejects.toThrow(
            /artboard control "button:Continue" is shown/,
        );
    });

    // inState: what only one state shows is checked in that state.
    const stateView = (inState: GatedView['inState']) => ({
        ...fixtureView('state-breaks-artboard.html'),
        expect: {
            headings: ['Gate fixture'],
            controls: [],
            states: ['filled' as const],
        },
        inState,
    });
    const fillState = (name: string) => {
        stateFixtures[name] = {
            filled: async (p) => {
                await p.getByRole('button', { name: 'Continue' }).click();
                await expect(p.getByText('Done')).toBeVisible();
            },
        };
    };

    test('passes when a state shows what inState lists for it', async ({
        page,
    }) => {
        const view = stateView({ filled: { texts: ['Done'] } });
        fillState(view.name);

        await assertArtboardStates(page, view);
    });

    test('fails when a state misses a control of its inState', async ({
        page,
    }) => {
        const view = stateView({ filled: { controls: ['button:Continue'] } });
        fillState(view.name);

        await expect(assertArtboardStates(page, view)).rejects.toThrow(
            /state "filled" shows the control "button:Continue"/,
        );
    });

    test('fails when a state misses a text of its inState', async ({
        page,
    }) => {
        const view = stateView({ filled: { texts: ['Saved'] } });
        fillState(view.name);

        await expect(assertArtboardStates(page, view)).rejects.toThrow(
            /state "filled" shows the text "Saved"/,
        );
    });

    test('fails when inState names a state the view never enters', async ({
        page,
    }) => {
        const view = stateView({ error: { texts: ['Done'] } });
        fillState(view.name);

        await expect(assertArtboardStates(page, view)).rejects.toThrow(
            /lists only its own states in inState/,
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
        test.use({ locale: view.locale ?? 'en-US' });

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
