// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({ submit: vi.fn() }));
vi.mock('@inertiajs/svelte', () => ({
    useHttp: () => ({ submit: mocks.submit }),
}));
vi.mock('@/routes/two-factor', () => ({
    qrCode: () => 'qr-code',
    secretKey: () => 'secret-key',
    recoveryCodes: () => 'recovery-codes',
}));

const { twoFactorAuthState } = await import('@/lib/twoFactorAuth.svelte');

/** Answers each route with the given value; an Error is thrown instead. */
function answer(byRoute: Record<string, unknown>) {
    mocks.submit.mockImplementation(async (route: string) => {
        const value = byRoute[route];

        if (value instanceof Error) {
            throw value;
        }

        return value;
    });
}

beforeEach(() => {
    mocks.submit.mockReset();
    twoFactorAuthState().clearTwoFactorAuthData();
});

describe('twoFactorAuthState', () => {
    it('loads the QR code and the setup key together', async () => {
        answer({
            'qr-code': { svg: '<svg/>', url: 'otpauth://x' },
            'secret-key': { secretKey: 'ABCD' },
        });
        const api = twoFactorAuthState();

        expect(api.hasSetupData()).toBe(false);
        await api.fetchSetupData();

        expect(api.state.qrCodeSvg).toBe('<svg/>');
        expect(api.state.manualSetupKey).toBe('ABCD');
        expect(api.hasSetupData()).toBe(true);
        expect(api.state.errors).toEqual([]);
    });

    it('records an error and clears the value when a request fails', async () => {
        answer({
            'qr-code': new Error('500'),
            'secret-key': new Error('500'),
        });
        const api = twoFactorAuthState();

        await api.fetchSetupData();

        expect(api.state.qrCodeSvg).toBeNull();
        expect(api.state.manualSetupKey).toBeNull();
        expect(api.state.errors).toEqual([
            'Failed to fetch QR code',
            'Failed to fetch a setup key',
        ]);
    });

    it('clears the setup data and its errors', async () => {
        answer({
            'qr-code': { svg: '<svg/>', url: 'otpauth://x' },
            'secret-key': new Error('network'),
        });
        const api = twoFactorAuthState();
        await api.fetchSetupData();

        api.clearSetupData();

        expect(api.state.qrCodeSvg).toBeNull();
        expect(api.state.errors).toEqual([]);
    });

    it('loads the recovery codes and drops them on a failure', async () => {
        answer({ 'recovery-codes': ['one', 'two'] });
        const api = twoFactorAuthState();

        await api.fetchRecoveryCodes();
        expect(api.state.recoveryCodesList).toEqual(['one', 'two']);

        answer({ 'recovery-codes': new Error('timeout') });
        await api.fetchRecoveryCodes();
        expect(api.state.recoveryCodesList).toEqual([]);
        expect(api.state.errors).toEqual(['Failed to fetch recovery codes']);
    });

    it('clears everything at once', async () => {
        answer({ 'recovery-codes': ['one'] });
        const api = twoFactorAuthState();
        await api.fetchRecoveryCodes();

        api.clearTwoFactorAuthData();

        expect(api.state.recoveryCodesList).toEqual([]);
        expect(api.hasSetupData()).toBe(false);
    });
});
