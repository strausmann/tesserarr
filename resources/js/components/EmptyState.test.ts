// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createRawSnippet, flushSync, mount, unmount } from 'svelte';
import { describe, expect, it } from 'vitest';
import EmptyState from './EmptyState.svelte';

describe('EmptyState', () => {
    it('says what is missing and offers the action that fixes it', () => {
        document.body.innerHTML = '';
        const action = createRawSnippet(() => ({
            render: () => '<a href="/invite">Invite someone</a>',
        }));
        const app = mount(EmptyState, {
            target: document.body,
            props: {
                title: 'No open invitations',
                body: 'An invitation stays here.',
                action,
            },
        });
        flushSync();
        expect(document.querySelector('.tr-empty-title')?.textContent).toBe(
            'No open invitations',
        );
        expect(document.querySelector('.tr-empty-body')?.textContent).toBe(
            'An invitation stays here.',
        );
        expect(document.querySelector('.tr-empty-action a')?.textContent).toBe(
            'Invite someone',
        );
        void unmount(app);
    });

    it('shows only the title when there is nothing more to say', () => {
        document.body.innerHTML = '';
        const app = mount(EmptyState, {
            target: document.body,
            props: { title: 'Nothing yet' },
        });
        flushSync();
        expect(document.querySelector('.tr-empty-body')).toBeNull();
        expect(document.querySelector('.tr-empty-action')).toBeNull();
        void unmount(app);
    });
});
