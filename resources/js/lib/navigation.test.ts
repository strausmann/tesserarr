// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { homeFor, isCurrent, navigationFor } from './navigation';

const en: Record<string, string> = JSON.parse(
    readFileSync(resolve(process.cwd(), 'lang', 'en.json'), 'utf8'),
);
const labels = (audience: 'friend' | 'operator') =>
    navigationFor(audience).flatMap((g) => g.items.map((i) => i.label));

describe('navigationFor', () => {
    it('gives a friend only the catalogue, their tokens and their account', () => {
        expect(labels('friend')).toEqual([
            'nav.catalog',
            'nav.my_tokens',
            'nav.account',
        ]);
    });

    it('gives the operator the four groups of the canvas', () => {
        expect(navigationFor('operator').map((g) => g.label)).toEqual([
            'nav.group.overview',
            'nav.group.media',
            'nav.group.operations',
            'nav.group.administration',
        ]);
        expect(labels('operator')).toContain('nav.users');
    });

    it('shows a friend nothing of the administration', () => {
        for (const admin of [
            'nav.users',
            'nav.roles',
            'nav.audit',
            'nav.connections',
            'nav.status',
        ]) {
            expect(labels('friend')).not.toContain(admin);
        }
    });

    it('leaves out what the account may not open, and an empty group', () => {
        const groups = navigationFor('operator', ['users.view', 'audit.view']);

        expect(groups.map((g) => g.label)).toEqual([
            'nav.group.overview',
            'nav.group.administration',
        ]);
        expect(groups.flatMap((g) => g.items.map((i) => i.label))).toEqual([
            'nav.home',
            'nav.users',
            'nav.audit',
        ]);
    });

    it('keeps the friend items the role carries', () => {
        expect(
            navigationFor('friend', ['catalog.browse']).flatMap((g) =>
                g.items.map((i) => i.label),
            ),
        ).toEqual(['nav.catalog', 'nav.account']);
    });

    it('gives a guest no navigation', () => {
        expect(navigationFor('guest')).toEqual([]);
    });

    it('has a string for every label', () => {
        for (const audience of ['friend', 'operator'] as const) {
            for (const group of navigationFor(audience)) {
                for (const key of [
                    group.label,
                    ...group.items.map((i) => i.label),
                ]) {
                    if (key !== null) {
                        expect(en[key], key).toBeTypeOf('string');
                    }
                }
            }
        }
    });
});

describe('homeFor and isCurrent', () => {
    it('leads a friend home to their shares and the operator to the dashboard', () => {
        expect(homeFor('friend')).toBe('/shared');
        expect(homeFor('operator')).toBe('/dashboard');
    });

    it('marks the page and the pages below it, not a page that only starts alike', () => {
        expect(isCurrent('/users', '/users')).toBe(true);
        expect(isCurrent('/users', '/users/12?tab=a')).toBe(true);
        expect(isCurrent('/users', '/users/')).toBe(true);
        expect(isCurrent('/users', '/usersettings')).toBe(false);
        expect(isCurrent('/dashboard', '/')).toBe(false);
    });
});
