<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Providers;

use App\OpenApi\PublicApiRouteResolver;
use App\OpenApi\TokenAbilityExtension;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Support\ServiceProvider;

/**
 * The OpenAPI document of the token API (Scramble). Scramble's own pages are
 * switched off; the API reference is Scalar at /docs/api (routes/web.php).
 */
final class OpenApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Before Scramble's provider boots, which would register /docs/api.
        Scramble::ignoreDefaultRoutes();
    }

    public function boot(): void
    {
        Scramble::configure()
            ->routes((new PublicApiRouteResolver)->__invoke(...))
            ->withOperationTransformers([TokenAbilityExtension::class])
            ->afterOpenApiGenerated(static function (OpenApi $openApi): void {
                // A relative server: the exported document must not carry the
                // APP_URL of the machine that exported it.
                $openApi->servers = [Server::make('/api/v1')->setDescription('This installation')];

                $openApi->components->addSecurityScheme(
                    TokenAbilityExtension::SCHEME,
                    SecurityScheme::http('bearer')->setDescription(
                        'An API token of a user (Settings > API tokens). The scopes of an operation name the '
                        .'permission the token must carry; the owner must hold it as well.',
                    ),
                );
            });
    }
}
