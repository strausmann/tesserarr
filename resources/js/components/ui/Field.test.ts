// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { flushSync, mount, unmount } from 'svelte';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fieldPurposes } from '@/lib/fields';
import type { FieldPurpose } from '@/lib/fields';
import Harness from '../test/FieldHarness.svelte';

vi.mock('@inertiajs/svelte', () => ({
    page: { props: { translations: { 'field.clear': 'Clear input' } } },
}));

let app: ReturnType<typeof mount> | undefined;
const $ = (sel: string) => document.querySelector<HTMLElement>(sel);
const field = () => $('#first') as HTMLInputElement;
const clearButton = () => document.querySelector<HTMLButtonElement>('button[aria-label="Clear input"]');

function render(props: Record<string, unknown> = {}) {
    document.body.innerHTML = '';
    app = mount(Harness, { target: document.body, props });
    flushSync();
}

afterEach(() => {
    if (app) void unmount(app);
    app = undefined;
});

describe('Field', () => {
    it.each(Object.keys(fieldPurposes) as FieldPurpose[])(
        'sets the attributes of %s from the table, not from the call site',
        (purpose) => {
            render({ purpose });
            const want = fieldPurposes[purpose];
            expect(field().getAttribute('type')).toBe(want.type);
            expect(field().getAttribute('inputmode')).toBe(want.inputmode);
            expect(field().getAttribute('autocomplete')).toBe(want.autocomplete);
            expect(field().getAttribute('autocapitalize')).toBe(want.autocapitalize);
            expect(field().getAttribute('autocorrect')).toBe(want.autocorrect);
            expect(field().getAttribute('spellcheck')).toBe(String(want.spellcheck));
        },
    );

    it('shows the clear button only while the field has a value', () => {
        render({ purpose: 'search' });
        expect(clearButton()).toBeNull();
        field().value = 'mat';
        field().dispatchEvent(new Event('input', { bubbles: true }));
        flushSync();
        expect(clearButton()).not.toBeNull();
    });

    it('never shows the clear button on a secret', () => {
        render({ purpose: 'password', value: 'hunter2' });
        expect(clearButton()).toBeNull();
    });

    it('keeps the focus in the field after the clear button and sends input', () => {
        render({ purpose: 'search', value: 'mat' });
        field().focus();
        const down = new PointerEvent('pointerdown', { bubbles: true, cancelable: true });
        clearButton()!.dispatchEvent(down);
        expect(down.defaultPrevented).toBe(true);
        clearButton()!.click();
        flushSync();
        expect(document.activeElement).toBe(field());
        expect(field().value).toBe('');
        expect($('#value')!.textContent).toBe('');
        expect($('#inputs')!.textContent).toBe('1');
        expect(clearButton()).toBeNull();
    });

    it('says next inside the form and moves Enter on to the next field', () => {
        render({ purpose: 'username' });
        field().focus();
        flushSync();
        expect(field().getAttribute('enterkeyhint')).toBe('next');
        const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        field().dispatchEvent(enter);
        expect(enter.defaultPrevented).toBe(true);
        expect(document.activeElement?.id).toBe('second');
        flushSync();
        expect(($('#second') as HTMLInputElement).getAttribute('enterkeyhint')).toBe('go');
    });

    it('leaves Enter on the last field to the form', () => {
        render({ purpose: 'name' });
        const second = $('#second')!;
        second.focus();
        const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        second.dispatchEvent(enter);
        expect(enter.defaultPrevented).toBe(false);
    });

    it('says search on a search and never moves on', () => {
        render({ purpose: 'search' });
        field().focus();
        flushSync();
        expect(field().getAttribute('enterkeyhint')).toBe('search');
        const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        field().dispatchEvent(enter);
        expect(enter.defaultPrevented).toBe(false);
    });

    it('ties the error and the hint to the field', () => {
        render({ purpose: 'email', error: 'Not an address', hint: 'We never share it' });
        expect(field().getAttribute('aria-invalid')).toBe('true');
        const ids = field().getAttribute('aria-describedby')!.split(' ');
        expect(ids.map((id) => document.getElementById(id)?.textContent)).toEqual([
            'We never share it',
            'Not an address',
        ]);
    });

    it('marks nothing while there is no error', () => {
        render({ purpose: 'email' });
        expect(field().hasAttribute('aria-invalid')).toBe(false);
        expect(field().hasAttribute('aria-describedby')).toBe(false);
    });
});
