// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { beforeEach, describe, expect, it } from 'vitest';
import {
    enterHint,
    enterMovesOn,
    fieldPurposes,
    focusFirstInvalid,
} from './fields';

describe('fieldPurposes', () => {
    // type, inputmode, autocomplete, autocapitalize, autocorrect, spellcheck, clearable
    it.each([
        ['search', 'search', 'search', 'off', 'none', 'off', false, true],
        ['name', 'text', 'text', 'off', 'sentences', 'on', true, true],
        ['username', 'text', 'text', 'username', 'none', 'off', false, true],
        ['email', 'email', 'email', 'email', 'none', 'off', false, true],
        [
            'password',
            'password',
            'text',
            'current-password',
            'none',
            'off',
            false,
            false,
        ],
        [
            'new-password',
            'password',
            'text',
            'new-password',
            'none',
            'off',
            false,
            false,
        ],
        [
            'one-time-code',
            'text',
            'numeric',
            'one-time-code',
            'none',
            'off',
            false,
            false,
        ],
        ['url', 'url', 'url', 'url', 'none', 'off', false, true],
        ['api-key', 'password', 'text', 'off', 'none', 'off', false, false],
        ['number', 'text', 'numeric', 'off', 'none', 'off', false, false],
    ] as const)(
        'gives %s the type %s, the keyboard %s and autocomplete %s',
        (
            purpose,
            type,
            inputmode,
            autocomplete,
            autocapitalize,
            autocorrect,
            spellcheck,
            clearable,
        ) => {
            expect(fieldPurposes[purpose]).toEqual({
                type,
                inputmode,
                autocomplete,
                autocapitalize,
                autocorrect,
                spellcheck,
                clearable,
            });
        },
    );

    it('never offers to clear a secret', () => {
        for (const purpose of [
            'password',
            'new-password',
            'api-key',
        ] as const) {
            expect(fieldPurposes[purpose].clearable, purpose).toBe(false);
        }
    });
});

describe('enterHint and enterMovesOn', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <form>
              <input id="a"><input id="b" type="hidden"><input id="c" disabled>
              <input id="d" type="checkbox"><textarea id="e"></textarea><input id="f">
              <button>Send</button>
            </form>
            <form id="nosubmit"><input id="g"></form>
            <input id="h">`;
    });
    const el = (id: string) => document.getElementById(id) as HTMLInputElement;

    it('says next inside a form and go on the last field', () => {
        expect(enterHint(el('a'), 'username')).toBe('next');
        expect(enterHint(el('f'), 'password')).toBe('go');
    });

    it('says done on the last field of a form without a submit button and outside a form', () => {
        expect(enterHint(el('g'), 'name')).toBe('done');
        expect(enterHint(el('h'), 'name')).toBe('done');
    });

    it('always says search on a search', () => {
        expect(enterHint(el('a'), 'search')).toBe('search');
    });

    it('moves Enter to the next typing field, skipping hidden, disabled and checkboxes', () => {
        expect(enterMovesOn(el('a'))).toBe(true);
        expect(document.activeElement?.id).toBe('e');
        el('f').focus();
        expect(enterMovesOn(el('f'))).toBe(false);
        expect(enterMovesOn(el('h'))).toBe(false);
    });
});

describe('focusFirstInvalid', () => {
    it('focuses the first invalid field', () => {
        document.body.innerHTML =
            '<input id="ok"><input id="x" aria-invalid="true"><input id="y" aria-invalid="true">';
        expect(focusFirstInvalid(document)?.id).toBe('x');
        expect(document.activeElement?.id).toBe('x');
    });

    it('returns null when nothing is invalid', () => {
        document.body.innerHTML = '<input aria-invalid="false">';
        expect(focusFirstInvalid(document)).toBeNull();
    });
});
