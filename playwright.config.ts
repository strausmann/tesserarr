// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { defineConfig, devices } from '@playwright/test';

// Browser tests against the built image (docker/Dockerfile, stage `e2e`) with
// PostgreSQL: scripts/e2e/run.sh starts both and runs this suite in the
// official Playwright container. E2E_BASE_URL points at the running image.
//
// No retries: the suite is a hard gate, and a test that needs a second run is
// a finding, not a pass.
export default defineConfig({
    testDir: './e2e',
    testMatch: '**/*.spec.ts',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: !!process.env.CI,
    outputDir: 'test-results',
    reporter: [
        ['list'],
        ['html', { open: 'never', outputFolder: 'playwright-report' }],
    ],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8080',
        trace: 'retain-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
