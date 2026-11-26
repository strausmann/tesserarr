<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);
use Dedoc\Scramble\OpenApiVersion;

// Scramble generates the OpenAPI document of the token API from the code.
// The routes are chosen by App\OpenApi\PublicApiRouteResolver (/api/v1
// without /api/v1/app); App\Providers\OpenApiServiceProvider adds the token
// abilities and the security scheme. CI exports the document and fails when
// the committed file differs (CONTRIBUTING.md, "API documentation").

return [
    // Only used for the server prefix and for stripping it from the paths;
    // the selection itself is the route resolver's.
    'api_path' => 'api/v1',

    'api_domain' => null,

    // `php artisan scramble:export` writes here; the website renders it.
    'export_path' => 'website/openapi/openapi.json',

    // No cache: the document is generated from the running code on each
    // request of /docs/api/openapi.json, so it can never be stale.
    'cache' => [
        'key' => 'scramble.openapi',
        'store' => null,
    ],

    'openapi_version' => OpenApiVersion::V3_1,

    'info' => [
        // The version of the API, not of the release: it changes with a
        // breaking change of /api/v1, never with a build.
        'version' => '1',

        'description' => 'The token API of Tesserarr. Every request carries an API token as a bearer token; '
            .'an operation lists the permission the token needs as the scope of `apiToken`. '
            .'The web interface uses its own session API, which is not part of this document.',
    ],

    'ui' => [
        'title' => 'Tesserarr API',
    ],

    // Never: the development tools would add a route to every instance role.
    'dev_tools' => [
        'enabled' => false,
    ],

    'renderer' => 'elements',

    'renderers' => [],

    'servers' => null,

    'enum_cases_description_strategy' => 'description',

    'enum_cases_names_strategy' => false,

    'enum_cases_auto_description_strategy' => false,

    'flatten_deep_query_parameters' => true,

    // The document route (routes/web.php) sits in the web group already; the
    // API reference is for accounts that may hold API tokens.
    'middleware' => ['auth', 'can:tokens.manage_own'],

    'extensions' => [],

    // Security comes from App\OpenApi\TokenAbilityExtension.
    'security_strategy' => null,
];
