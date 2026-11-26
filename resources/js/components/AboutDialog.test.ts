// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { flushSync, mount, tick, unmount } from 'svelte';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    page: { props: {} as Record<string, unknown> },
    router: { reload: vi.fn() },
}));

vi.mock('@inertiajs/svelte', () => inertia);

const { default: AboutDialog } = await import('./AboutDialog.svelte');

const translations = {
    'about.title': 'About Tesserarr',
    'about.version': 'Version',
    'about.release': 'Newer release',
    'about.release.checking': 'Checking …',
    'about.release.newer': 'Version :version has been released.',
    'about.release.notes': 'Release notes',
    'about.release.current': 'This is the newest release.',
    'about.release.unknown': 'Not known.',
    'about.licence': 'Licence',
    'about.licence.name': 'AGPL-3.0-or-later.',
    'about.source': 'Source code',
    'about.fonts': 'Fonts',
    'about.fonts.name': 'Atkinson Hyperlegible.',
    'about.fonts.licence': 'Licence text',
    'dialog.close': 'Close',
};

let app: ReturnType<typeof mount> | undefined;

async function open(props: Record<string, unknown>) {
    inertia.page.props = { translations, version: '0.1.0', ...props };
    app = mount(AboutDialog, { target: document.body, props: { open: true } });
    flushSync();
    await tick();
}

const release = () =>
    document.querySelector<HTMLElement>('[data-testid="about-release"]');

beforeEach(() => {
    document.body.innerHTML = '';
    inertia.router.reload.mockReset();
});

afterEach(() => {
    if (app) {
        void unmount(app);
    }
    app = undefined;
});

describe('AboutDialog', () => {
    it('shows the version, the licence and the source', async () => {
        await open({ audience: 'friend' });

        const dialog = document.querySelector('[role="dialog"]')!;
        expect(dialog.textContent).toContain('About Tesserarr');
        expect(document.querySelector('.tr-about-version')?.textContent).toBe(
            '0.1.0',
        );
        const source = [...dialog.querySelectorAll('a')].find(
            (a) => a.textContent === 'Source code',
        )!;
        expect(source.getAttribute('href')).toBe(
            'https://github.com/strausmann/tesserarr',
        );
        expect(source.getAttribute('rel')).toBe('noopener noreferrer');
        // A friend is not told about releases and causes no request.
        expect(release()).toBeNull();
        expect(inertia.router.reload).not.toHaveBeenCalled();
    });

    it('asks for the newest release when the operator opens it', async () => {
        await open({ audience: 'operator' });

        expect(inertia.router.reload).toHaveBeenCalledOnce();
        expect(inertia.router.reload.mock.calls[0][0]).toMatchObject({
            only: ['release'],
        });
        expect(release()?.textContent).toContain('Checking');
    });

    it('names a newer release as text with the link of the server', async () => {
        await open({
            audience: 'operator',
            release: {
                latest: '0.2.0<b>',
                newer: true,
                url: 'https://github.com/strausmann/tesserarr/releases/tag/v0.2.0',
            },
        });

        expect(release()?.textContent).toContain(
            'Version 0.2.0<b> has been released.',
        );
        expect(release()?.querySelector('b')).toBeNull();
        expect(release()?.querySelector('a')?.getAttribute('href')).toBe(
            'https://github.com/strausmann/tesserarr/releases/tag/v0.2.0',
        );
    });

    it('says when the running version is the newest or nothing is known', async () => {
        await open({
            audience: 'operator',
            release: { latest: '0.1.0', newer: false, url: '' },
        });
        expect(release()?.textContent).toContain('This is the newest release.');
        void unmount(app!);

        const done = inertia.router.reload.mockImplementation(
            (options: { onFinish?: () => void }) => options.onFinish?.(),
        );
        await open({ audience: 'operator', release: null });
        expect(done).toHaveBeenCalled();
        expect(release()?.textContent).toContain('Not known.');
    });
});
