// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import type {
    ArtboardExpectation,
    ArtboardState,
    ArtboardStateElements,
} from './views';

/**
 * Puts a freshly opened view into one state of its artboard and asserts what
 * only that state shows. Keyed by the view name of `gatedViews`.
 */
export type StateFixture = (page: Page) => Promise<void>;

export const stateFixtures: Record<
    string,
    Partial<Record<ArtboardState, StateFixture>>
> = {
    Login: {
        empty: async (page) => {
            await expect(
                page.getByRole('textbox', { name: 'Username or email' }),
            ).toHaveValue('');
            await expect(
                page.getByLabel('Password', { exact: true }),
            ).toHaveValue('');
        },
        error: async (page) => {
            await page
                .getByRole('textbox', { name: 'Username or email' })
                .fill('nobody');
            await page
                .getByLabel('Password', { exact: true })
                .fill('not-the-password');
            await page.getByRole('button', { name: 'Log in' }).click();
            await expect(
                page.getByText('These credentials do not match our records.'),
            ).toBeVisible();
        },
    },
    // A fresh instance: nothing to watch, invite or log yet.
    Dashboard: {
        empty: async (page) => {
            await expect(
                page.getByRole('heading', { name: 'Startseite', level: 1 }),
            ).toBeVisible();
        },
    },
    // At least the operator of the sign-in is listed.
    Users: {
        filled: async (page) => {
            await expect(
                page
                    .getByRole('cell', { name: 'e2e-operator' })
                    .or(page.getByText('e2e-operator'))
                    .first(),
            ).toBeVisible();
        },
    },
    // An own role "Mitbetreuer" between the built-in ones, as on the artboard.
    Roles: {
        filled: async (page) => {
            await ensureOwnRole(page);
        },
    },
    // An entry exists once the own role is created (role.create).
    'Audit log': {
        filled: async (page) => {
            await ensureOwnRole(page);
            await page.goto('/audit');
            await expect(
                page.getByRole('table', { name: 'Protokolleinträge' }),
            ).toBeVisible();
        },
    },
    // A friend nothing has been shared with yet.
    'Friend home': {
        empty: async (page) => {
            await expect(
                page.getByRole('heading', {
                    name: 'Was mit dir geteilt wird',
                    level: 1,
                }),
            ).toBeVisible();
        },
    },
};

/** Creates the own role "Mitbetreuer" on /roles unless it exists already. */
async function ensureOwnRole(page: Page): Promise<void> {
    await page.goto('/roles');
    const remove = page.getByRole('button', {
        name: 'Rolle „Mitbetreuer“ löschen',
    });
    if ((await remove.count()) === 0) {
        await page.getByRole('button', { name: 'Rolle anlegen' }).click();
        await page
            .getByRole('dialog')
            .getByLabel('Name der Rolle')
            .fill('Mitbetreuer');
        await page
            .getByRole('dialog')
            .getByRole('button', { name: 'Rolle anlegen' })
            .click();
    }
    await expect(remove).toBeVisible();
}

type AriaRole = Parameters<Page['getByRole']>[0];

/** Finds a control written `role:name` (see ArtboardExpectation). */
export function control(page: Page, spec: string): Locator {
    const at = spec.indexOf(':');
    if (at < 1) {
        throw new Error(`artboard control "${spec}" is not written role:name`);
    }
    const role = spec.slice(0, at);
    const name = spec.slice(at + 1);

    return role === 'password'
        ? page
              .getByLabel(name, { exact: true })
              .and(page.locator('input[type="password"]'))
        : page.getByRole(role as AriaRole, { name, exact: true });
}

/**
 * Asserts that the page shows every heading and control of the artboard, each
 * exactly once. Fails with the name of the first one that is missing.
 */
export async function assertArtboard(
    page: Page,
    exp: ArtboardExpectation,
): Promise<void> {
    for (const heading of exp.headings) {
        await expect(
            page.getByRole('heading', { name: heading, exact: true }),
            `artboard heading "${heading}" is shown`,
        ).toHaveCount(1);
    }
    for (const spec of exp.controls) {
        const found = control(page, spec);
        await expect(found, `artboard control "${spec}" is shown`).toHaveCount(
            1,
        );
        await expect(
            found,
            `artboard control "${spec}" is visible`,
        ).toBeVisible();
    }
}

/**
 * Asserts what only this state of the artboard shows: every heading and
 * control exactly once and visible, every text visible.
 */
export async function assertStateElements(
    page: Page,
    state: ArtboardState,
    elements: ArtboardStateElements[ArtboardState],
): Promise<void> {
    for (const heading of elements?.headings ?? []) {
        await expect(
            page.getByRole('heading', { name: heading, exact: true }),
            `state "${state}" shows the heading "${heading}"`,
        ).toHaveCount(1);
    }
    for (const spec of elements?.controls ?? []) {
        const found = control(page, spec);
        await expect(
            found,
            `state "${state}" shows the control "${spec}"`,
        ).toHaveCount(1);
        await expect(
            found,
            `state "${state}" shows the control "${spec}" visibly`,
        ).toBeVisible();
    }
    for (const text of elements?.texts ?? []) {
        await expect(
            page.getByText(text, { exact: true }),
            `state "${state}" shows the text "${text}"`,
        ).toBeVisible();
    }
}

/** States of `inState` that the view never enters (not in `expect.states`). */
export function unreachableStates(view: {
    expect: ArtboardExpectation;
    inState?: ArtboardStateElements;
}): string[] {
    return Object.keys(view.inState ?? {}).filter(
        (state) => !view.expect.states.includes(state as ArtboardState),
    );
}

/**
 * Puts the view into each state of its artboard in turn; a state without a
 * fixture fails. In each state the whole expectation holds, plus what only
 * that state shows (`inState`).
 */
export async function assertArtboardStates(
    page: Page,
    view: {
        name: string;
        path: string;
        expect: ArtboardExpectation;
        inState?: ArtboardStateElements;
    },
): Promise<void> {
    expect(
        unreachableStates(view),
        `view "${view.name}" lists only its own states in inState`,
    ).toEqual([]);
    for (const state of view.expect.states) {
        const fixture = stateFixtures[view.name]?.[state];
        expect(
            fixture,
            `view "${view.name}" has a fixture for the state "${state}"`,
        ).toBeDefined();
        await page.goto(view.path);
        await fixture!(page);
        // The artboard holds in every state, not only on the first render.
        await assertArtboard(page, view.expect);
        await assertStateElements(page, state, view.inState?.[state]);
    }
}
