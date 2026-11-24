// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { fileURLToPath } from 'node:url';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { defineConfig } from 'vitest/config';

// Unit tests of the web interface. The browser tests in e2e/ run with
// Playwright (playwright.config.ts), never here.
//
// Coverage minimums (CONTRIBUTING.md, "Local CI checks"): resources/js/lib at
// 80 %. The 70 % for components applies from the first Tesserarr component
// (Field, Dialog); the pull request that adds it adds its glob to `include`
// and `thresholds`. The starter-kit components are replaced from task 8 on and
// are not measured.
export default defineConfig({
    plugins: [svelte()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'jsdom',
        restoreMocks: true,
        unstubGlobals: true,
        coverage: {
            provider: 'v8',
            include: ['resources/js/lib/**/*.ts'],
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
            },
        },
    },
});
