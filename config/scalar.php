<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Scalar: the interactive API reference at /docs/api, for signed-in users
// only (routes/web.php). It loads nothing from a CDN: the bundle of the pinned
// npm package @scalar/api-reference is copied into the build by vite.config.ts.
// Requests go from the browser straight to this installation, never through
// Scalar's proxy. Options: https://scalar.com/products/api-references/configuration

return [
    // Unused: the route is registered by routes/web.php, not by the package
    // (App\Providers\ScalarServiceProvider).
    'domain' => null,
    'path' => '/docs/api',
    'middleware' => ['web', 'auth'],

    // The document Scramble generates from the running code (routes/web.php).
    'url' => '/docs/api/openapi.json',
    'content' => null,
    'file' => null,
    'sources' => [],

    'cdn' => '/build/scalar/standalone.js',

    'configuration' => [
        // The colours of the web interface, in its light and dark theme.
        'theme' => 'none',
        'customCss' => (string) file_get_contents(resource_path('css/scalar.css')),
        'layout' => 'modern',

        // Empty: no request ever passes Scalar's public proxy.
        'proxyUrl' => '',

        'showSidebar' => true,
        'hideModels' => false,
        'documentDownloadType' => 'json',
        'hideTestRequestButton' => false,
        'hideSearch' => false,
        'hideDarkModeToggle' => false,
        'searchHotKey' => 'k',
        'metaData' => [
            'title' => 'Tesserarr API reference',
        ],
        'favicon' => '/favicon.svg',
        'hiddenClients' => [],
        'defaultHttpClient' => [
            'targetKey' => 'shell',
            'clientKey' => 'curl',
        ],

        // Scalar's default fonts come from its own servers; use the system fonts.
        'withDefaultFonts' => false,

        'defaultOpenAllTags' => false,
        'defaultOpenFirstTag' => true,
        'showOperationId' => true,
        'hideClientButton' => false,
        'expandAllModelSections' => false,
        'expandAllResponses' => false,
        'expandAllSchemaProperties' => false,
        'modelsSectionLabel' => 'Schemas',
        'operationTitleSource' => 'summary',
        'orderRequiredPropertiesFirst' => true,
        'orderSchemaPropertiesBy' => 'alpha',
        'operationsSorter' => 'alpha',

        // A token typed into the reference stays in the page, not in localStorage.
        'persistAuth' => false,
        'telemetry' => false,
        'showDeveloperTools' => 'never',
        'agent' => ['disabled' => true],
    ],
];
