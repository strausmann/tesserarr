// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { fileURLToPath } from 'node:url';
import type { Page } from '@playwright/test';

/**
 * Serves e2e/fixtures/<name>.html under /_fixtures/<name>.html of the
 * application origin, so a fixture page runs with the same origin, cookies and
 * browser settings as the application.
 */
export async function serveFixtures(page: Page): Promise<void> {
    // The session probe of clean.html: an expected 401, which the gate accepts.
    await page.route('**/_fixtures/session-probe', (route) =>
        route.fulfill({
            status: 401,
            contentType: 'application/json',
            body: '{"message":"Unauthenticated."}',
        }),
    );
    await page.route('**/_fixtures/*.html', (route) =>
        route.fulfill({
            path: fileURLToPath(
                new URL(
                    `../fixtures/${new URL(route.request().url()).pathname.split('/').pop()}`,
                    import.meta.url,
                ),
            ),
            contentType: 'text/html; charset=utf-8',
        }),
    );
}
