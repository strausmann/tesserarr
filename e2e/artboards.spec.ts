// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { unreachableStates } from './support/artboard';
import { artboards } from './support/artboards';

// The artboard expectations of docs/designs/0.1.0 are data that UI tasks take
// into gatedViews later. These checks need no browser: they keep the data
// consistent before any view uses it.

test.describe('artboard expectations', () => {
    test('name every artboard once', () => {
        const names = artboards.map((a) => a.artboard);
        expect(names.filter((n, i) => names.indexOf(n) !== i)).toEqual([]);
    });

    for (const artboard of artboards) {
        test(`${artboard.artboard} keys inState only by its own states`, () => {
            expect(
                unreachableStates(artboard),
                `inState of ${artboard.artboard} names a state missing from expect.states`,
            ).toEqual([]);
        });
    }
});
