<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\OpenApi;

use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Routes the OpenAPI tests register under /api/v1 and /api/v1/app. They are
 * never registered by the application itself.
 */
final class ProbeController
{
    /**
     * Read the probe.
     *
     * Answers the name of the probe. Used by the OpenAPI tests only.
     */
    #[Endpoint(operationId: 'probe.show')]
    #[Response(200, 'The probe', examples: [['name' => 'probe']])]
    #[Response(403, 'The token lacks the permission catalog.browse')]
    public function show(): JsonResponse
    {
        return new JsonResponse(['name' => 'probe']);
    }

    /**
     * Rename the probe.
     *
     * Changes the name of the probe. Used by the OpenAPI tests only.
     */
    #[Endpoint(operationId: 'probe.update')]
    #[BodyParameter('name', 'The new name of the probe', required: true, type: 'string', example: 'renamed')]
    #[Response(200, 'The renamed probe', examples: [['name' => 'renamed']])]
    #[Response(403, 'The token lacks the permission catalog.manage')]
    #[Response(422, 'The name is missing or not a string')]
    public function update(Request $request): JsonResponse
    {
        $request->validate(['name' => ['required', 'string']]);

        return new JsonResponse(['name' => $request->string('name')->toString()]);
    }

    /**
     * Archive the probe.
     *
     * Marks the probe as archived. The 403 and 422 answers are the ones
     * Scramble infers from abort_unless() and validate(), without attributes.
     */
    #[Endpoint(operationId: 'probe.archive')]
    #[Response(200, 'The archived probe', examples: [['name' => 'probe', 'archived' => true]])]
    public function archive(Request $request): JsonResponse
    {
        $request->validate([
            /**
             * Why the probe is archived.
             *
             * @example no longer needed
             */
            'reason' => ['required', 'string'],
        ]);

        abort_unless($request->user() !== null, 403);

        return new JsonResponse(['name' => 'probe', 'archived' => true]);
    }
}
