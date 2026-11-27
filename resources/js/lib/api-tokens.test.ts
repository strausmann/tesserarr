// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it, vi } from 'vitest';
import { APP_HEADER, appHeaders, appRequest, xsrfToken } from './app-api';
import type { TokenRow } from './api-tokens';
import {
    authorizationHeader,
    CUSTOM,
    inCatalogueOrder,
    issueToken,
    presetOf,
    revokeToken,
    revokeUrl,
} from './api-tokens';

const presets = [
    { key: 'arr', permissions: ['catalog.browse'], available: true },
    {
        key: 'invitations',
        permissions: ['invitations.create'],
        available: false,
    },
];

const row = (over: Partial<TokenRow> = {}): TokenRow => ({
    id: 7,
    name: 'script',
    permissions: ['catalog.browse'],
    created_at: null,
    last_used_at: null,
    expires_at: null,
    mine: true,
    owner: null,
    ...over,
});

const json = (status: number, body: unknown) =>
    vi.fn(async () => new Response(JSON.stringify(body), { status }));

describe('the session API request', () => {
    it('sends the app header, JSON and the CSRF token', () => {
        expect(appHeaders('XSRF-TOKEN=a%3Db')).toEqual({
            'X-Requested-With': 'tesserarr',
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': 'a=b',
        });
        expect(appHeaders('other=1')).not.toHaveProperty('X-XSRF-TOKEN');
        expect(APP_HEADER['X-Requested-With']).toBe('tesserarr');
        expect(xsrfToken('x=1')).toBeNull();
    });

    it('keeps the cookie on the same origin and sends no body without one', async () => {
        const send = vi.fn(async () => new Response(null, { status: 204 }));
        await appRequest('/api/v1/app/x', 'DELETE', undefined, send);

        const init = (
            send.mock.calls[0] as unknown as [string, RequestInit]
        )[1];
        expect(init.credentials).toBe('same-origin');
        expect(init).not.toHaveProperty('body');
        expect(
            (init.headers as Record<string, string>)['X-Requested-With'],
        ).toBe('tesserarr');
    });
});

describe('presets', () => {
    it('names the preset whose permissions are exactly the selection', () => {
        expect(presetOf(['catalog.browse'], presets)).toBe('arr');
        expect(presetOf(['catalog.browse', 'status.view'], presets)).toBe(
            CUSTOM,
        );
        expect(presetOf([], presets)).toBe(CUSTOM);
        // A preset the account cannot grant is never chosen.
        expect(presetOf(['invitations.create'], presets)).toBe(CUSTOM);
    });

    it('keeps the order of the catalogue', () => {
        expect(inCatalogueOrder(['b', 'a', 'x'], ['a', 'b', 'c'])).toEqual([
            'a',
            'b',
        ]);
    });
});

describe('issuing a token', () => {
    const request = {
        name: 'script',
        permissions: ['catalog.browse'],
        expires: '90' as const,
    };

    it('returns the token once on 201', async () => {
        const send = json(201, { token: row(), plain_text: '7|tsr_abc' });

        await expect(issueToken(request, send)).resolves.toEqual({
            ok: true,
            token: row(),
            plainText: '7|tsr_abc',
        });
        const init = (
            send.mock.calls[0] as unknown as [string, RequestInit]
        )[1];
        expect(init.method).toBe('POST');
        expect(JSON.parse(init.body as string)).toEqual(request);
    });

    it('maps the validation errors to their fields', async () => {
        const send = json(422, {
            errors: { name: ['too long'], 'permissions.1': ['unknown'] },
        });

        await expect(issueToken(request, send)).resolves.toEqual({
            ok: false,
            reason: 'validation',
            errors: { name: 'too long', permissions: 'unknown' },
        });
    });

    it('names the refused permissions', async () => {
        const send = json(403, {
            message: 'privilege_escalation',
            refused: ['users.manage'],
        });

        await expect(issueToken(request, send)).resolves.toEqual({
            ok: false,
            reason: 'privilege_escalation',
            refused: ['users.manage'],
        });
    });

    it('asks for the password again on 423 and sees a sign-out on 401', async () => {
        await expect(issueToken(request, json(423, {}))).resolves.toMatchObject(
            { reason: 'confirm_password' },
        );
        await expect(issueToken(request, json(401, {}))).resolves.toMatchObject(
            { reason: 'signed_out' },
        );
    });

    it('fails quietly on a server error, a broken answer and no network', async () => {
        await expect(issueToken(request, json(500, {}))).resolves.toMatchObject(
            { reason: 'failed' },
        );
        await expect(
            issueToken(
                request,
                vi.fn(async () => new Response('not json', { status: 201 })),
            ),
        ).resolves.toMatchObject({ reason: 'failed' });
        await expect(
            issueToken(
                request,
                vi.fn(async () => Promise.reject(new TypeError('offline'))),
            ),
        ).resolves.toMatchObject({ reason: 'failed' });
    });
});

describe('revoking a token', () => {
    it('uses the own path for an own token and the administrative one otherwise', () => {
        expect(revokeUrl(row())).toBe('/api/v1/app/tokens/7');
        expect(revokeUrl(row({ mine: false }))).toBe(
            '/api/v1/app/admin/tokens/7',
        );
    });

    it('counts 204 and 404 as gone, anything else and no network as not', async () => {
        await expect(
            revokeToken(
                row(),
                vi.fn(async () => new Response(null, { status: 204 })),
            ),
        ).resolves.toBe(true);
        await expect(revokeToken(row(), json(404, {}))).resolves.toBe(true);
        await expect(revokeToken(row(), json(403, {}))).resolves.toBe(false);
        await expect(
            revokeToken(
                row(),
                vi.fn(async () => Promise.reject(new TypeError('offline'))),
            ),
        ).resolves.toBe(false);
    });

    it('writes the header a script sends', () => {
        expect(authorizationHeader('7|tsr_x')).toBe(
            'Authorization: Bearer 7|tsr_x',
        );
    });
});
