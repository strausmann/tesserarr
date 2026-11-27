// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { appRequest } from './app-api';

/**
 * API tokens in the interface (Tokens.dc.html). The page lists them from its
 * Inertia props; issuing and revoking go through the session API, so a new
 * token arrives in one JSON answer and lives only in the page's memory until
 * the page is left. It is never put in the URL, the history or storage.
 */

export type TokenRow = {
    id: number;
    name: string;
    permissions: string[];
    created_at: string | null;
    last_used_at: string | null;
    expires_at: string | null;
    mine: boolean;
    owner: { id: number; username: string; display_name: string } | null;
};

export type Preset = { key: string; permissions: string[]; available: boolean };

/** The expiry choices of the form (App\Http\Controllers\Api\App\TokenController::EXPIRY_CHOICES). */
export const EXPIRY_CHOICES = ['30', '90', '365', 'never'] as const;
export type Expiry = (typeof EXPIRY_CHOICES)[number];
export const DEFAULT_EXPIRY: Expiry = '90';

export const ISSUE_URL = '/api/v1/app/tokens';

/**
 * The permissions the form shows first: what a script usually needs. Every
 * other grantable permission (the administrative ones among them) is one
 * click further ("Alle Rechte zeigen").
 */
export const COMMON_PERMISSIONS = [
    'catalog.browse',
    'catalog.view_all',
    'status.view',
    'invitations.create',
];

/** The radio value of a free selection (no preset). */
export const CUSTOM = 'custom';

export type IssueRequest = {
    name: string;
    permissions: string[];
    expires: Expiry;
};

export type IssueResult =
    | { ok: true; token: TokenRow; plainText: string }
    | { ok: false; reason: 'validation'; errors: Record<string, string> }
    | { ok: false; reason: 'privilege_escalation'; refused: string[] }
    | { ok: false; reason: 'confirm_password' | 'signed_out' | 'failed' };

/** The preset whose permissions are exactly $selected, or CUSTOM. */
export function presetOf(selected: string[], presets: Preset[]): string {
    const chosen = [...selected].sort().join();
    const match = presets.find(
        (p) => p.available && [...p.permissions].sort().join() === chosen,
    );

    return match ? match.key : CUSTOM;
}

/** $selected in the order of $catalogue (the page's grantable list). */
export function inCatalogueOrder(
    selected: string[],
    catalogue: string[],
): string[] {
    return catalogue.filter((p) => selected.includes(p));
}

/** Issues a token for the signed-in account. Never throws. */
export async function issueToken(
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

    if (response.status === 201 && typeof body.plain_text === 'string') {
        return {
            ok: true,
            token: body.token as TokenRow,
            plainText: body.plain_text,
        };
    }
    if (response.status === 422) {
        const errors: Record<string, string> = {};
        for (const [field, messages] of Object.entries(
            (body.errors ?? {}) as Record<string, string[]>,
        )) {
            errors[field.split('.')[0]] = Array.isArray(messages)
                ? String(messages[0])
                : String(messages);
        }

        return { ok: false, reason: 'validation', errors };
    }
    if (response.status === 403 && body.message === 'privilege_escalation') {
        return {
            ok: false,
            reason: 'privilege_escalation',
            refused: Array.isArray(body.refused)
                ? (body.refused as string[])
                : [],
        };
    }
    if (response.status === 423) {
        return { ok: false, reason: 'confirm_password' };
    }
    if (response.status === 401) {
        return { ok: false, reason: 'signed_out' };
    }

    return { ok: false, reason: 'failed' };
}

/** The URL that revokes $token: the own path, or the administrative one for another account's. */
export function revokeUrl(token: TokenRow): string {
    return token.mine
        ? `/api/v1/app/tokens/${token.id}`
        : `/api/v1/app/admin/tokens/${token.id}`;
}

/** Revokes a token. Resolves true when it is gone (also when it was gone already). */
export async function revokeToken(
    token: TokenRow,
    send?: typeof fetch,
): Promise<boolean> {
    try {
        const response = await appRequest(
            revokeUrl(token),
            'DELETE',
            undefined,
            send,
        );

        return response.status === 204 || response.status === 404;
    } catch {
        return false;
    }
}

/** The header line a script sends, with the token in it. */
export function authorizationHeader(plainText: string): string {
    return `Authorization: Bearer ${plainText}`;
}
