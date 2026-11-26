// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { flushSync, mount, tick, unmount } from 'svelte';
import type { Component } from 'svelte';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    page: { props: {} as Record<string, unknown>, url: '/' },
    router: { put: vi.fn() },
}));

vi.mock('@inertiajs/svelte', async (original) => ({
    ...(await original<Record<string, unknown>>()),
    page: inertia.page,
    router: inertia.router,
}));

const { default: General } = await import('./General.svelte');

let app: ReturnType<typeof mount> | undefined;

type Source = 'environment' | 'stored' | 'default';

function setting(
    value: string | number,
    source: Source = 'default',
    env = 'X',
) {
    return { value, source, env, env_invalid: false, default: value };
}

const baseSettings = () => ({
    instance_display_name: setting('Tesserarr'),
    locale_default: setting('en'),
    time_zone: setting('UTC', 'default', 'TZ'),
    ui_page_size: setting(50),
    ui_theme_default: setting('system'),
    invitations_default_days: setting(7),
    audit_retention_days: setting(365),
    backups_keep: setting(5),
});

const props = (settings: Record<string, unknown> = baseSettings()) => ({
    settings,
    options: {
        time_zones: ['UTC', 'Europe/Berlin'],
        locales: ['de', 'en'],
        page_sizes: [25, 50, 100],
        themes: ['system', 'light', 'dark'],
    },
    environment: [
        { env: 'APP_URL', value: 'https://tesserarr.example.org' },
        { env: 'TESSERARR_TRUSTED_PROXIES', value: '' },
    ],
    secrets: [
        { env: 'APP_KEY', set: true },
        { env: 'DB_PASSWORD', set: false },
    ],
    request: { client: '203.0.113.7', proxy: '198.51.100.2' },
});

async function render(
    p: Record<string, unknown>,
    errors: Record<string, string> = {},
) {
    inertia.page.props = { translations: {}, errors };
    app = mount(General as Component<Record<string, unknown>>, {
        target: document.body,
        props: p,
    });
    flushSync();
    await tick();
}

const input = (id: string) => document.getElementById(id) as HTMLInputElement;
const button = (text: string) =>
    [...document.querySelectorAll('button')].find(
        (b) => b.textContent?.trim() === text,
    ) as HTMLButtonElement;

async function type(el: HTMLInputElement, value: string) {
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    await tick();
}

beforeEach(() => {
    document.body.innerHTML = '';
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    inertia.router.put.mockReset();
});

afterEach(() => {
    if (app) {
        void unmount(app);
    }
    app = undefined;
});

describe('General settings page', () => {
    it('shows the values in force and keeps Save closed until something changes', async () => {
        await render(props());

        expect(input('time-zone').value).toBe('UTC');
        expect(button('general.save').disabled).toBe(true);
        expect(button('general.discard').disabled).toBe(true);
        expect(document.body.textContent).toContain(
            'https://tesserarr.example.org',
        );
        expect(document.body.textContent).toContain('general.env.not_set');
    });

    it('sends every free field when saving', async () => {
        await render(props());
        await type(input('time-zone'), 'Europe/Berlin');

        expect(button('general.save').disabled).toBe(false);
        document
            .querySelector('form')!
            .dispatchEvent(
                new Event('submit', { cancelable: true, bubbles: true }),
            );

        expect(inertia.router.put).toHaveBeenCalledOnce();
        const [url, body] = inertia.router.put.mock.calls[0];
        expect(url).toBe('/settings/general');
        expect(body).toMatchObject({
            time_zone: 'Europe/Berlin',
            ui_page_size: '50',
            backups_keep: '5',
        });
        expect(Object.keys(body)).toHaveLength(8);
    });

    it('locks an overridden field, names its variable and leaves it out of the save', async () => {
        const settings = {
            ...baseSettings(),
            time_zone: setting('Europe/Berlin', 'environment', 'TZ'),
        };
        await render(props(settings));

        expect(input('time-zone').disabled).toBe(true);
        expect(document.body.textContent).toContain('general.overridden');
    });

    it('does not send the overridden field', async () => {
        const settings = {
            ...baseSettings(),
            time_zone: setting('Europe/Berlin', 'environment', 'TZ'),
        };
        await render(props(settings));
        const days = document.querySelector<HTMLInputElement>(
            'input[inputmode="numeric"]',
        )!;
        await type(days, '9');

        document
            .querySelector('form')!
            .dispatchEvent(
                new Event('submit', { cancelable: true, bubbles: true }),
            );

        const body = inertia.router.put.mock.calls[0][1];
        expect(body).not.toHaveProperty('time_zone');
        expect(Object.keys(body)).toHaveLength(7);
    });

    it('says when an environment value is ignored and keeps the field open', async () => {
        const settings = baseSettings();
        settings.ui_page_size = {
            ...setting(50),
            env_invalid: true,
            env: 'TESSERARR_PAGE_SIZE',
        };
        await render(props(settings));

        expect(document.body.textContent).toContain('general.env_invalid');
        expect(
            document.querySelector<HTMLButtonElement>('#page-size-0')!.disabled,
        ).toBe(false);
    });

    it('keeps digits only in a number field and at most four of them', async () => {
        await render(props());
        const days = document.querySelector<HTMLInputElement>(
            'input[inputmode="numeric"]',
        )!;

        await type(days, '1a2b3c4d5');

        expect(days.value).toBe('1234');
    });

    it('discards what was typed', async () => {
        await render(props());
        await type(input('time-zone'), 'Europe/Berlin');

        button('general.discard').click();
        await tick();

        expect(input('time-zone').value).toBe('UTC');
        expect(button('general.save').disabled).toBe(true);
    });

    it('shows the server message under the field it belongs to', async () => {
        await render(props(), {
            time_zone: 'The time zone is invalid.',
            ui_page_size: 'Not allowed.',
        });

        expect(document.body.textContent).toContain(
            'The time zone is invalid.',
        );
        expect(document.body.textContent).toContain('Not allowed.');
    });

    it('keeps what was typed when the server refuses the save', async () => {
        const holder = { set: (_next: Record<string, unknown>) => {} };
        inertia.page.props = { translations: {}, errors: {} };
        const { default: Harness } =
            await import('@/components/test/GeneralHarness.svelte');
        app = mount(Harness as Component<Record<string, unknown>>, {
            target: document.body,
            props: { initial: props(), holder },
        });
        flushSync();
        await type(input('time-zone'), 'Mars/Olympus');
        await type(
            document.querySelector<HTMLInputElement>(
                'input[inputmode="numeric"]',
            )!,
            '9',
        );

        // The refusal redirects back: new props objects holding the stored values, and an error.
        inertia.page.props = {
            translations: {},
            errors: { time_zone: 'Not a time zone.' },
        };
        holder.set(props());
        flushSync();
        await tick();

        expect(input('time-zone').value).toBe('Mars/Olympus');
        expect(
            document.querySelector<HTMLInputElement>(
                'input[inputmode="numeric"]',
            )!.value,
        ).toBe('9');
        expect(button('general.save').disabled).toBe(false);
    });

    it('takes the stored values when the server changed them', async () => {
        const holder = { set: (_next: Record<string, unknown>) => {} };
        inertia.page.props = { translations: {}, errors: {} };
        const { default: Harness } =
            await import('@/components/test/GeneralHarness.svelte');
        app = mount(Harness as Component<Record<string, unknown>>, {
            target: document.body,
            props: { initial: props(), holder },
        });
        flushSync();
        await type(input('time-zone'), 'Europe/Berlin');

        // After the save: the stored value is the typed one.
        holder.set(
            props({
                ...baseSettings(),
                time_zone: setting('Europe/Berlin', 'stored', 'TZ'),
            }),
        );
        flushSync();
        await tick();

        expect(input('time-zone').value).toBe('Europe/Berlin');
        expect(button('general.save').disabled).toBe(true);
    });

    it('copies a value and shows the check for a moment', async () => {
        vi.useFakeTimers();
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        await render(props());

        document
            .querySelector<HTMLButtonElement>(
                'button[aria-label="general.copy"]',
            )!
            .click();
        await vi.advanceTimersByTimeAsync(0);

        expect(writeText).toHaveBeenCalledWith('https://tesserarr.example.org');
        await vi.advanceTimersByTimeAsync(2000);
        vi.useRealTimers();
    });

    it('does not break on a name of 60 characters and an unbroken proxy list', async () => {
        const settings = {
            ...baseSettings(),
            instance_display_name: setting('Ä'.repeat(60)),
        };
        const worst = props(settings);
        worst.environment[1].value = '2001:db8:0:0:0:0:0:1/128,'.repeat(12);

        await render(worst);

        expect(
            document.querySelector<HTMLInputElement>('input[type="text"]')!
                .value,
        ).toHaveLength(60);
        expect(document.body.textContent).toContain('2001:db8');
    });
});

describe('Segmented', () => {
    it('moves and chooses with the arrow keys, one tab stop for the group', async () => {
        await render(props());
        const first = document.getElementById('locale-0') as HTMLButtonElement;
        const second = document.getElementById('locale-1') as HTMLButtonElement;
        expect(second.getAttribute('aria-checked')).toBe('true');
        expect(second.tabIndex).toBe(0);
        expect(first.tabIndex).toBe(-1);

        second.dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'ArrowRight',
                bubbles: true,
                cancelable: true,
            }),
        );
        await tick();

        expect(first.getAttribute('aria-checked')).toBe('true');
        second.dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'Tab',
                bubbles: true,
                cancelable: true,
            }),
        );
    });

    it('ignores the keys when it is locked', async () => {
        const settings = {
            ...baseSettings(),
            locale_default: setting(
                'en',
                'environment',
                'TESSERARR_DEFAULT_LOCALE',
            ),
        };
        await render(props(settings));
        const second = document.getElementById('locale-1') as HTMLButtonElement;

        second.dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'ArrowLeft',
                bubbles: true,
                cancelable: true,
            }),
        );
        await tick();

        expect(second.getAttribute('aria-checked')).toBe('true');
        expect(second.disabled).toBe(true);
    });
});
