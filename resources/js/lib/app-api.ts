// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { appFetch } from './updates.svelte';

/**
 * The header the session API (/api/v1/app) requires on every request
 * (App\Http\Middleware\RequireAppHeader, ADR-0007). A foreign page cannot
 * set it without a CORS preflight, which fails.
 */
export const APP_HEADER = { 'X-Requested-With': 'tesserarr' } as const;

/** Laravel's CSRF token as the browser holds it (cookie XSRF-TOKEN). */
export function xsrfToken(cookie: string = document.cookie): string | null {
    const found = cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return found ? decodeURIComponent(found.slice('XSRF-TOKEN='.length)) : null;
}

/**
 * The headers of a request to the session API: the app header, JSON, and the
 * CSRF token the browser holds (cookie XSRF-TOKEN).
 */
export function appHeaders(
    cookie: string = document.cookie,
): Record<string, string> {
    const token = xsrfToken(cookie);

    return {
        ...APP_HEADER,
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(token ? { 'X-XSRF-TOKEN': token } : {}),
    };
}

/** A request to the session API with the session cookie and those headers. */
export function appRequest(
    url: string,
    method: 'GET' | 'POST' | 'PUT' | 'DELETE',
    body?: unknown,
    send: typeof fetch = (input, init) => appFetch(input, init),
): Promise<Response> {
    return send(url, {
        method,
        credentials: 'same-origin',
        headers: appHeaders(),
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
}
