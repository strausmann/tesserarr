// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { flushSync, mount, tick, unmount } from 'svelte';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Harness from '../test/DialogHarness.svelte';

vi.mock('@inertiajs/svelte', () => ({
    page: { props: { translations: { 'dialog.close': 'Close', 'nav.close': 'Close menu' } } },
}));

let app: ReturnType<typeof mount>;
const $ = (sel: string) => document.querySelector<HTMLElement>(sel);
const press = (key: string, shiftKey = false) =>
    document.activeElement!.dispatchEvent(
        new KeyboardEvent('keydown', { key, shiftKey, bubbles: true, cancelable: true }),
    );

async function openDialog(props: Record<string, unknown> = {}) {
    app = mount(Harness, { target: document.body, props });
    $('#opener')!.focus();
    $('#opener')!.click();
    flushSync();
    await tick();
    await tick();
}

beforeEach(() => {
    document.body.innerHTML = '';
});
afterEach(() => void unmount(app));

describe('Dialog', () => {
    it('opens with the focus on the first field and a name from its title', async () => {
        await openDialog();
        const dialog = $('[role=dialog]')!;
        expect(dialog.getAttribute('aria-modal')).toBe('true');
        expect(document.getElementById(dialog.getAttribute('aria-labelledby')!)?.textContent).toBe('Add share');
        expect(document.activeElement?.id).toBe('field');
    });

    it('keeps Tab inside: from the last element to the first and back', async () => {
        await openDialog();
        $('#save')!.focus();
        press('Tab');
        expect(document.activeElement?.getAttribute('aria-label')).toBe('Close');
        press('Tab', true);
        expect(document.activeElement?.id).toBe('save');
    });

    it('closes on Escape and gives the focus back to the opener', async () => {
        await openDialog();
        press('Escape');
        flushSync();
        expect($('[role=dialog]')).toBeNull();
        expect(document.activeElement?.id).toBe('opener');
        expect($('#closed')!.textContent).toBe('1');
    });

    it('lets an open list take the first Escape (two steps)', async () => {
        await openDialog({ listOpen: true });
        press('Escape');
        flushSync();
        expect($('#list')).toBeNull();
        expect($('[role=dialog]')).not.toBeNull();
        press('Escape');
        flushSync();
        expect($('[role=dialog]')).toBeNull();
    });

    it('closes on a tap on the dimmed area without moving the focus first', async () => {
        await openDialog();
        const layer = $('.tr-dialog-layer')!;
        const down = new PointerEvent('pointerdown', { bubbles: true, cancelable: true, isPrimary: true, button: 0 });
        layer.dispatchEvent(down);
        expect(down.defaultPrevented).toBe(true);
        expect(document.activeElement?.id).toBe('field');
        layer.dispatchEvent(new PointerEvent('pointerup', { bubbles: true }));
        flushSync();
        expect($('[role=dialog]')).toBeNull();
    });

    it('stays open for a press that starts inside or turns into a scroll', async () => {
        await openDialog();
        const layer = $('.tr-dialog-layer')!;
        $('#field')!.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, isPrimary: true, button: 0 }));
        layer.dispatchEvent(new PointerEvent('pointerup', { bubbles: true }));
        layer.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, isPrimary: true, button: 0 }));
        layer.dispatchEvent(new PointerEvent('pointercancel', { bubbles: true }));
        layer.dispatchEvent(new PointerEvent('pointerup', { bubbles: true }));
        flushSync();
        expect($('[role=dialog]')).not.toBeNull();
    });

    it('locks the page scroll while open', async () => {
        await openDialog();
        expect(document.documentElement.style.overflow).toBe('hidden');
        $('[data-dialog-close]')!.click();
        flushSync();
        expect(document.documentElement.style.overflow).toBe('');
    });

    it('names the close button of the drawer for the menu', async () => {
        await openDialog({ variant: 'drawer' });
        expect($('[data-dialog-close]')!.getAttribute('aria-label')).toBe('Close menu');
        expect($('.tr-dialog-layer')!.dataset.variant).toBe('drawer');
    });
});
