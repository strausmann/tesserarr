// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => {
    let handler: ((event: unknown) => void) | undefined;

    return {
        router: {
            on: vi.fn((_name: string, h: (event: unknown) => void) => {
                handler = h;
            }),
        },
        toast: {
            success: vi.fn(),
            info: vi.fn(),
            warning: vi.fn(),
            error: vi.fn(),
        },
        emit: (detail: unknown) =>
            handler?.(new CustomEvent('flash', { detail })),
    };
});
vi.mock('@inertiajs/svelte', () => ({ router: mocks.router }));
vi.mock('svelte-sonner', () => ({ toast: mocks.toast }));

const { initializeFlashToast } = await import('@/lib/flash-toast');

describe('initializeFlashToast', () => {
    it('listens to the flash event of the router', () => {
        initializeFlashToast();

        expect(mocks.router.on).toHaveBeenCalledWith(
            'flash',
            expect.any(Function),
        );
    });

    it('shows a flashed toast with its type and message', () => {
        initializeFlashToast();

        mocks.emit({ flash: { toast: { type: 'error', message: 'Saved?' } } });

        expect(mocks.toast.error).toHaveBeenCalledWith('Saved?');
        expect(mocks.toast.success).not.toHaveBeenCalled();
    });

    it('shows nothing when the flash carries no toast', () => {
        initializeFlashToast();

        mocks.emit({ flash: {} });
        mocks.emit(undefined);

        for (const show of Object.values(mocks.toast)) {
            expect(show).not.toHaveBeenCalled();
        }
    });
});
