// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * `ui.collapsed`: which sections the user collapsed, kept on the server per
 * user so it holds on every device (App\Http\Controllers\Ui\
 * CollapsedSectionController). The page gets the stored map as the Inertia
 * prop `ui.collapsed`; a section without a stored value uses the default of
 * its page.
 */
export const COLLAPSED_URL = '/api/v1/app/me/ui/collapsed';

/** The stored state of a section, or its default when nothing is stored. */
export function isCollapsed(
    stored: Record<string, boolean> | undefined,
    key: string,
    fallback: boolean,
): boolean {
    const value = stored?.[key];

    return typeof value === 'boolean' ? value : fallback;
}

/** Laravel's CSRF token as the browser holds it (cookie XSRF-TOKEN). */
export function xsrfToken(cookie: string = document.cookie): string | null {
    const found = cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return found ? decodeURIComponent(found.slice('XSRF-TOKEN='.length)) : null;
}

/**
 * Stores the state of one section. Resolves true when the server took it,
 * false when it refused or could not be reached; the caller keeps showing
 * what the user chose either way, and the next page load shows what is
 * stored.
 */
export async function saveCollapsed(
    key: string,
    collapsed: boolean,
    send: typeof fetch = fetch,
): Promise<boolean> {
    const token = xsrfToken();
    try {
        const response = await send(COLLAPSED_URL, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(token ? { 'X-XSRF-TOKEN': token } : {}),
            },
            body: JSON.stringify({ section: key, collapsed }),
        });

        return response.ok;
    } catch {
        return false;
    }
}
