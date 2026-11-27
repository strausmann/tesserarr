// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { fileURLToPath } from 'node:url';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { defineConfig } from 'vitest/config';

// Unit tests of the web interface. The browser tests in e2e/ run with
// Playwright (playwright.config.ts), never here.
//
// Coverage minimums (CONTRIBUTING.md, "Local CI checks"): resources/js/lib at
// 80 %, components at 70 %.
//
// The Tesserarr components (resources/js/components/ui/{Dialog,Field,
// Collapsible,SearchDialog}.svelte, EmptyState, AboutDialog, UpdateNotice)
// are measured at 70 %.
// WHITELIST: the other files in resources/js/components
// Reason: starter-kit components, replaced by the tasks that rebuild their
//   pages (sign-in 11, second factor 12, settings 8b); each adds its files to
//   `components` below.
// Issue: #83 (https://github.com/strausmann/tesserarr/issues/83)
// Expires: 2027-02-22
const components = [
    'resources/js/components/AboutDialog.svelte',
    'resources/js/components/EmptyState.svelte',
    'resources/js/components/UpdateNotice.svelte',
    'resources/js/components/ui/Collapsible.svelte',
    'resources/js/components/ui/Dialog.svelte',
    'resources/js/components/ui/Field.svelte',
    'resources/js/components/ui/SearchDialog.svelte',
    // Pages with logic of their own (task 8).
    'resources/js/pages/admin/Audit.svelte',
    'resources/js/pages/admin/Roles.svelte',
    'resources/js/pages/admin/Users.svelte',
    // Task 8b.
    'resources/js/pages/admin/settings/General.svelte',
    'resources/js/components/ui/Segmented.svelte',
    // Task 8a.
    'resources/js/components/account/AccountEmailForm.svelte',
    'resources/js/components/account/ActivityList.svelte',
    'resources/js/pages/account/Index.svelte',
    'resources/js/pages/account/Activity.svelte',
    'resources/js/pages/account/Security.svelte',
    'resources/js/pages/admin/UserDetail.svelte',
    'resources/js/pages/auth/ConfirmEmail.svelte',
    // Task 9.
    'resources/js/components/tokens/TokenForm.svelte',
    'resources/js/components/tokens/TokenManager.svelte',
    'resources/js/components/tokens/TokenUsage.svelte',
    'resources/js/pages/friend/Home.svelte',
];

export default defineConfig({
    plugins: [svelte()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
        // Mounting a component needs Svelte's browser build in jsdom.
        conditions: ['browser'],
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'jsdom',
        restoreMocks: true,
        unstubGlobals: true,
        coverage: {
            provider: 'v8',
            include: ['resources/js/lib/**/*.ts', ...components],
            exclude: ['resources/js/**/*.test.ts'],
            reporter: ['text', 'lcov'],
            reportsDirectory: 'coverage/js',
            thresholds: {
                'resources/js/lib/**': {
                    lines: 80,
                    functions: 80,
                    branches: 80,
                    statements: 80,
                },
                ...Object.fromEntries(
                    components.map((file) => [
                        file,
                        {
                            lines: 70,
                            functions: 70,
                            branches: 70,
                            statements: 70,
                        },
                    ]),
                ),
            },
        },
    },
});
