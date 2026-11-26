import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { readFileSync } from 'node:fs';
import { defineConfig, lazyPlugins, type Plugin } from 'vite-plus';

const isSvelteCheck = process.argv.some((argument) =>
    argument.includes('svelte-check'),
);

if (isSvelteCheck) {
    process.env.LARAVEL_BYPASS_ENV_CHECK ??= '1';
}

// The API reference at /docs/api (config/scalar.php) runs Scalar from this
// installation, never from a CDN: the standalone bundle of the pinned
// @scalar/api-reference package is copied verbatim to public/build/scalar/,
// next to its licence text. It is not part of the app bundle.
function scalarApiReference(): Plugin {
    return {
        name: 'scalar-api-reference',
        generateBundle() {
            this.emitFile({
                type: 'asset',
                fileName: 'scalar/standalone.js',
                source: readFileSync(
                    new URL(
                        'node_modules/@scalar/api-reference/dist/browser/standalone.js',
                        import.meta.url,
                    ),
                ),
            });
            this.emitFile({
                type: 'asset',
                fileName: 'scalar/LICENSE.txt',
                source: readFileSync(
                    new URL(
                        'resources/licenses/scalar-api-reference-MIT.txt',
                        import.meta.url,
                    ),
                ),
            });
        },
    };
}

// The version this bundle is built for: TESSERARR_VERSION, the same value
// the image passes to the server (docker/Dockerfile), default 0.0.0-dev on
// both sides. lib/updates.svelte.ts compares it with the X-Tesserarr-Version
// header of every answer.
const buildVersion = process.env.TESSERARR_VERSION || '0.0.0-dev';

export default defineConfig({
    define: {
        __TESSERARR_BUILD_VERSION__: JSON.stringify(buildVersion),
    },
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                // The type of the mockup canvas (resources/css/tokens.css).
                bunny('Atkinson Hyperlegible', {
                    weights: [400, 700],
                }),
                bunny('Atkinson Hyperlegible Mono', {
                    weights: [400, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        svelte(),
        wayfinder({
            formVariants: true,
            // The image build generates the Wayfinder files in the PHP stage and
            // runs Vite in a Node-only stage, where it sets this to a no-op.
            command: process.env.WAYFINDER_COMMAND,
        }),
        scalarApiReference(),
    ]),
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'coverage/**',
            'playwright-report/**',
            'test-results/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
            // The documentation website has its own package and checks.
            'website/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        // Code only. Prose, YAML and TOML are written by hand (Vale checks the
        // prose); licence-policy.yml is JSON on purpose and commitlint.config.cjs
        // is a bootstrap file that main carries unchanged.
        ignorePatterns: [
            '.github/**',
            '**/*.md',
            '**/*.yml',
            '**/*.yaml',
            '**/*.toml',
            '.vale/**',
            'commitlint.config.cjs',
            'coverage/**',
            'playwright-report/**',
            'test-results/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
            // Generated: a Scramble export (scripts/test_openapi_quality.py).
            'scripts/fixtures/**',
            'website/**',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
