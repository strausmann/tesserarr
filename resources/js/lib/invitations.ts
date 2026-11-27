// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { appRequest } from './app-api';

/**
 * Invitations in the interface (Users.dc.html, task 10). Issuing and
 * revoking go through the session API; a new link arrives in one JSON answer
 * and lives only in the page's memory until the dialog closes. It is never
 * put in the URL, the history or storage.
 */

export type InvitationRow = {
    id: number;
    kind: 'access' | 'recovery';
    note: string | null;
    expires_at: string;
    created_at: string | null;
    created_by: string | null;
    via: 'ui' | 'cli' | 'api';
    for_user: string | null;
};

/** The validity choices of the dialog, in days (Users.dc.html: 1, 7, 30). */
export const VALIDITY_CHOICES = ['1', '7', '30'] as const;

/**
 * The choices with the instance default among them (general setting
 * "Validity of new invitations", 1 to 90 days), in order. The default is
 * what the dialog preselects.
 */
export function validityChoices(defaultDays: number): string[] {
    const days =
        Number.isInteger(defaultDays) && defaultDays >= 1 && defaultDays <= 90
            ? defaultDays
            : 7;

    return [...new Set([...VALIDITY_CHOICES.map(Number), days])]
        .sort((a, b) => a - b)
        .map(String);
}

export const ISSUE_URL = '/api/v1/app/invitations';

export type IssueRequest = { days: number; note?: string; user?: number };

export type IssueResult =
    | { ok: true; invitation: InvitationRow; url: string }
    | { ok: false; reason: 'validation'; errors: Record<string, string> }
    | { ok: false; reason: 'privilege_escalation' | 'signed_out' | 'failed' };

/** The request body: the note only when it has text. */
export function issueRequest(
    days: string,
    note: string,
    user?: number,
): IssueRequest {
    const trimmed = note.trim();

    return {
        days: Number(days),
        ...(trimmed === '' ? {} : { note: trimmed }),
        ...(user === undefined ? {} : { user }),
    };
}

/** Issues an invitation. Never throws. */
export async function issueInvitation(
    request: IssueRequest,
    send?: typeof fetch,
): Promise<IssueResult> {
    let response: Response;
    try {
        response = await appRequest(ISSUE_URL, 'POST', request, send);
    } catch {
        return { ok: false, reason: 'failed' };
    }

    const body = (await response.json().catch(() => ({}))) as Record<
        string,
        unknown
    >;

    if (response.status === 201 && typeof body.url === 'string') {
        return {
            ok: true,
            invitation: body.invitation as InvitationRow,
            url: body.url,
        };
    }
    if (response.status === 422) {
        const errors: Record<string, string> = {};
        for (const [field, messages] of Object.entries(
            (body.errors ?? {}) as Record<string, string[] | string>,
        )) {
            errors[field] = Array.isArray(messages)
                ? String(messages[0])
                : String(messages);
        }

        return { ok: false, reason: 'validation', errors };
    }
    if (response.status === 403) {
        return { ok: false, reason: 'privilege_escalation' };
    }
    if (response.status === 401 || response.status === 419) {
        return { ok: false, reason: 'signed_out' };
    }

    return { ok: false, reason: 'failed' };
}

export type RevokeResult = 'revoked' | 'gone' | 'denied' | 'failed';

/** Revokes an invitation. 404 means somebody redeemed or revoked it meanwhile. Never throws. */
export async function revokeInvitation(
    id: number,
    send?: typeof fetch,
): Promise<RevokeResult> {
    try {
        const response = await appRequest(
            `${ISSUE_URL}/${id}`,
            'DELETE',
            undefined,
            send,
        );
        if (response.status === 204) {
            return 'revoked';
        }

        return response.status === 404
            ? 'gone'
            : response.status === 403
              ? 'denied'
              : 'failed';
    } catch {
        return 'failed';
    }
}

/** Whole days until $expiresAt, counted up (a link valid for 2 hours is "1 day"); 0 once it is due. */
export function daysLeft(expiresAt: string, now: Date = new Date()): number {
    const ms = new Date(expiresAt).getTime() - now.getTime();

    return ms <= 0 ? 0 : Math.ceil(ms / 86_400_000);
}

/**
 * The token of an invitation link, read from the fragment the browser never
 * sends (/invite#<token>). Empty when the link was cut or altered.
 */
export function tokenFromHash(hash: string): string {
    const raw = hash.replace(/^#/, '');
    try {
        const token = decodeURIComponent(raw).trim();

        return /^[A-Za-z0-9_-]{43}$/.test(token) ? token : '';
    } catch {
        return '';
    }
}

/** A pasted link or bare token, reduced to the token (the fallback field of the redemption page). */
export function tokenFromInput(value: string): string {
    const text = value.trim();
    const hash = text.indexOf('#');

    return tokenFromHash(hash >= 0 ? text.slice(hash) : text);
}
