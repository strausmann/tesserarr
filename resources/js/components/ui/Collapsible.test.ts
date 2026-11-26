// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createRawSnippet, flushSync, mount, unmount } from 'svelte';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Collapsible from './Collapsible.svelte';

const props = vi.hoisted(() => ({ value: { ui: { collapsed: {} as Record<string, boolean> } } }));
vi.mock('@inertiajs/svelte', () => ({
    page: {
        get props() {
            return props.value;
        },
    },
}));

let app: ReturnType<typeof mount> | undefined;
const body = createRawSnippet(() => ({ render: () => '<p id="content">Three services are out of date</p>' }));
const button = () => document.querySelector<HTMLButtonElement>('button[aria-expanded]')!;

function render(extra: Record<string, unknown> = {}) {
    document.body.innerHTML = '';
    const save = vi.fn(async () => true);
    app = mount(Collapsible, {
        target: document.body,
        props: { section: 'dashboard.attention', title: 'Needs attention', summary: '3 items', save, children: body, ...extra },
    });
    flushSync();

    return save;
}

afterEach(() => {
    if (app) void unmount(app);
    app = undefined;
});

describe('Collapsible', () => {
    it('is a heading whose switch says whether it is open', () => {
        render();
        expect(document.querySelector('h2 button')).toBe(button());
        expect(button().getAttribute('aria-expanded')).toBe('true');
        expect(document.getElementById(button().getAttribute('aria-controls')!)?.hidden).toBe(false);
    });

    it('shows the new state at once, stores the last one shortly after and shows the summary', async () => {
        vi.useFakeTimers();
        const save = render();
        button().click();
        flushSync();
        expect(button().getAttribute('aria-expanded')).toBe('false');
        expect(document.getElementById(button().getAttribute('aria-controls')!)?.hidden).toBe(true);
        expect(document.body.textContent).toContain('3 items');
        button().click();
        button().click();
        flushSync();
        await vi.advanceTimersByTimeAsync(300);
        expect(save).toHaveBeenCalledOnce();
        expect(save).toHaveBeenCalledWith('dashboard.attention', true);
        vi.useRealTimers();
    });

    it('stores a waiting change when the section goes away', () => {
        const save = render();
        button().click();
        void unmount(app!);
        app = undefined;
        expect(save).toHaveBeenCalledWith('dashboard.attention', true);
    });

    it('starts from the stored value of the page props', () => {
        props.value = { ui: { collapsed: { 'dashboard.attention': true } } };
        render();
        expect(button().getAttribute('aria-expanded')).toBe('false');
    });

    it('starts from the default of the page when nothing is stored', () => {
        props.value = { ui: { collapsed: {} } };
        render({ collapsedByDefault: true });
        expect(button().getAttribute('aria-expanded')).toBe('false');
    });

    it('takes the map it is given over the page props', () => {
        props.value = { ui: { collapsed: { 'dashboard.attention': true } } };
        render({ stored: { 'dashboard.attention': false }, level: 3 });
        expect(button().getAttribute('aria-expanded')).toBe('true');
        expect(document.querySelector('h3 button')).toBe(button());
    });
});
