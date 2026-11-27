// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it, vi } from 'vitest';
import {
    daysLeft,
    issueInvitation,
    issueRequest,
    revokeInvitation,
    tokenFromHash,
    tokenFromInput,
    validityChoices,
} from './invitations';

// 43 characters of base64url, as a real token, but plainly made up.
const TOKEN = 'EXAMPLE_invitation_token_' + '0'.repeat(18);

function answer(status: number, body: unknown = {}): typeof fetch {
    return vi.fn(
        async () =>
            new Response(status === 204 ? null : JSON.stringify(body), {
                status,
            }),
    ) as unknown as typeof fetch;
}

describe('issueRequest', () => {
    it('sends the days as a number and the note only when it has text', () => {
        expect(issueRequest('7', '  ')).toEqual({ days: 7 });
        expect(issueRequest('1', ' Noa ')).toEqual({ days: 1, note: 'Noa' });
        expect(issueRequest('30', '', 12)).toEqual({ days: 30, user: 12 });
    });
});

describe('issueInvitation', () => {
    it('returns the invitation and its link on 201', async () => {
        const send = answer(201, {
            invitation: { id: 3 },
            url: `https://tesserarr.example.org/invite#${TOKEN}`,
        });
        const result = await issueInvitation({ days: 7 }, send);

        expect(result).toEqual({
            ok: true,
            invitation: { id: 3 },
            url: `https://tesserarr.example.org/invite#${TOKEN}`,
        });
        const [url, init] = (send as unknown as ReturnType<typeof vi.fn>).mock
            .calls[0];
        expect(url).toBe('/api/v1/app/invitations');
        expect(init.method).toBe('POST');
        expect(init.headers['X-Requested-With']).toBe('tesserarr');
        expect(JSON.parse(init.body)).toEqual({ days: 7 });
    });

    it('maps validation, refusal, signed-out and other errors', async () => {
        expect(
            await issueInvitation(
                { days: 0 },
                answer(422, { errors: { days: ['Too short.'] } }),
            ),
        ).toEqual({
            ok: false,
            reason: 'validation',
            errors: { days: 'Too short.' },
        });
        expect(
            await issueInvitation(
                { days: 7 },
                answer(403, { message: 'privilege_escalation' }),
            ),
        ).toEqual({ ok: false, reason: 'privilege_escalation' });
        expect(await issueInvitation({ days: 7 }, answer(419))).toEqual({
            ok: false,
            reason: 'signed_out',
        });
        expect(await issueInvitation({ days: 7 }, answer(500))).toEqual({
            ok: false,
            reason: 'failed',
        });
        // A 201 without a link is no success.
        expect(await issueInvitation({ days: 7 }, answer(201, {}))).toEqual({
            ok: false,
            reason: 'failed',
        });
    });

    it('reports a network error as failed instead of throwing', async () => {
        const send = vi.fn(async () => {
            throw new TypeError('network');
        }) as unknown as typeof fetch;
        expect(await issueInvitation({ days: 7 }, send)).toEqual({
            ok: false,
            reason: 'failed',
        });
    });
});

describe('revokeInvitation', () => {
    it('maps 204, 404, 403, other answers and a network error', async () => {
        expect(await revokeInvitation(4, answer(204))).toBe('revoked');
        expect(await revokeInvitation(4, answer(404))).toBe('gone');
        expect(await revokeInvitation(4, answer(403))).toBe('denied');
        expect(await revokeInvitation(4, answer(500))).toBe('failed');
        const broken = vi.fn(async () => {
            throw new TypeError('network');
        }) as unknown as typeof fetch;
        expect(await revokeInvitation(4, broken)).toBe('failed');
    });

    it('sends DELETE to the invitation', async () => {
        const send = answer(204);
        await revokeInvitation(9, send);
        const [url, init] = (send as unknown as ReturnType<typeof vi.fn>).mock
            .calls[0];
        expect(url).toBe('/api/v1/app/invitations/9');
        expect(init.method).toBe('DELETE');
    });
});

describe('daysLeft', () => {
    const now = new Date('2026-11-27T12:00:00Z');

    it('counts started days up and is 0 once due', () => {
        expect(daysLeft('2026-11-27T14:00:00Z', now)).toBe(1);
        expect(daysLeft('2026-12-04T12:00:00Z', now)).toBe(7);
        expect(daysLeft('2026-11-27T12:00:00Z', now)).toBe(0);
        expect(daysLeft('2026-11-20T12:00:00Z', now)).toBe(0);
    });
});

describe('tokens from links', () => {
    it('reads the token from the fragment and refuses anything else', () => {
        expect(tokenFromHash(`#${TOKEN}`)).toBe(TOKEN);
        expect(tokenFromHash('')).toBe('');
        expect(tokenFromHash(`#${TOKEN.slice(1)}`)).toBe('');
        expect(tokenFromHash('#%E0%A4%A')).toBe('');
        expect(tokenFromHash('#<script>')).toBe('');
    });

    it('takes a pasted link or a bare token', () => {
        expect(
            tokenFromInput(` https://tesserarr.example.org/invite#${TOKEN} `),
        ).toBe(TOKEN);
        expect(tokenFromInput(TOKEN)).toBe(TOKEN);
        expect(tokenFromInput('https://tesserarr.example.org/invite')).toBe('');
    });
});

describe('validityChoices', () => {
    it('adds the instance default to the choices, in order, and falls back to 7 for a bad value', () => {
        expect(validityChoices(7)).toEqual(['1', '7', '30']);
        expect(validityChoices(14)).toEqual(['1', '7', '14', '30']);
        expect(validityChoices(90)).toEqual(['1', '7', '30', '90']);
        expect(validityChoices(0)).toEqual(['1', '7', '30']);
        expect(validityChoices(2.5)).toEqual(['1', '7', '30']);
    });
});
