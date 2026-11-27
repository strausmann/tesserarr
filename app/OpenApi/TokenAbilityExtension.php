<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\OpenApi;

use App\Http\SelfService;
use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\RouteInfo;
use ReflectionClass;

/**
 * Documents the token ability an operation needs, read from the route's
 * `can:<permission>` middleware: the same middleware that checks it, so the
 * document cannot claim another permission than the route enforces.
 *
 * The ability becomes the scope list of the `apiToken` security requirement
 * (OpenAPI 3.1 allows role names there for schemes other than OAuth 2). A
 * route without a `can:` middleware gets no security requirement, which the
 * API quality gate (scripts/openapi_quality.py) refuses for /api/v1.
 *
 * A self-service route (#[SelfService], such as GET /me) needs a valid token
 * and no permission: it gets the `apiToken` requirement without a scope and
 * the extension `x-self-service: true`, which the gate accepts instead.
 */
final class TokenAbilityExtension extends OperationExtension
{
    public const SCHEME = 'apiToken';

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $abilities = [];

        foreach ($routeInfo->route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                continue;
            }

            // can:<ability>[,<model parameter>…]; only the ability is a permission.
            $ability = explode(',', substr($middleware, 4))[0];

            if ($ability !== '' && ! in_array($ability, $abilities, true)) {
                $abilities[] = $ability;
            }
        }

        if ($abilities !== []) {
            $operation->addSecurity(new SecurityRequirement([self::SCHEME => $abilities]));
        } elseif (self::isSelfService($routeInfo)) {
            $operation->addSecurity(new SecurityRequirement([self::SCHEME => []]));
            $operation->setExtensionProperty('self-service', true);
        }
    }

    private static function isSelfService(RouteInfo $routeInfo): bool
    {
        $class = $routeInfo->className();
        $method = $routeInfo->methodName();

        if ($class === null || ! class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->getAttributes(SelfService::class) !== []) {
            return true;
        }

        return $method !== null && $reflection->hasMethod($method)
            && $reflection->getMethod($method)->getAttributes(SelfService::class) !== [];
    }
}
