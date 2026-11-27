<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Audit\Actor;
use App\Authorization\PrivilegeEscalation;
use App\Http\Controllers\Controller;
use App\Invitations\InvitationNotRevocable;
use App\Invitations\InvitationRequest;
use App\Invitations\InvitationService;
use App\Models\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;

/**
 * Invitations on the token path (/api/v1/invitations, Spec 9.1 "per API"):
 * for a script or a bot that hands out invitations. The answer carries the
 * link once; only the hash of its token is stored.
 */
final class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    /**
     * Create an invitation.
     *
     * Makes an invitation for one new account and answers its link, which
     * can be redeemed once. The link is in this answer only; pass it on in a
     * way you trust. Needs `invitations.create`.
     */
    #[Endpoint(operationId: 'invitations.store')]
    #[BodyParameter('days', 'How many days the link stays valid, 1 to 90; the instance default (7) when left out', required: false, type: 'integer', example: 7)]
    #[BodyParameter('note', 'A label to recognise the invitation by, at most 100 characters', required: false, type: 'string', example: 'For Sam')]
    #[Response(201, 'The new invitation and its link', examples: [[
        'id' => 12,
        'url' => 'https://tesserarr.example.org/invite#EXAMPLE_invitation_token_000000000000000000',
        'expires_at' => '2026-12-04T18:00:00+00:00',
    ]])]
    #[Response(403, 'The token or its owner lacks invitations.create')]
    #[Response(422, 'days is out of range, or note is too long')]
    public function store(Request $request, #[CurrentUser('sanctum')] User $user): JsonResponse
    {
        [$ttl, $note] = InvitationRequest::read($request);

        try {
            $issued = $this->invitations->issue($this->actor($user), ttl: $ttl, note: $note);
        } catch (PrivilegeEscalation) {
            return response()->json(['message' => 'owner_lacks_permission'], 403);
        }

        return response()->json([
            'id' => $issued->invitation->id,
            'url' => $issued->url,
            'expires_at' => $issued->invitation->expires_at->toIso8601String(),
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    /**
     * Revoke an invitation.
     *
     * The link stops working at once. Only an invitation nobody has
     * redeemed yet can be revoked. Needs `invitations.revoke`.
     */
    #[Endpoint(operationId: 'invitations.destroy')]
    #[PathParameter('invitation', 'The id of the invitation, as the answer of creating it names it', type: 'integer', example: 12)]
    #[Response(403, 'The token or its owner lacks invitations.revoke')]
    #[Response(404, 'No open invitation with this id')]
    public function destroy(int $invitation, #[CurrentUser('sanctum')] User $user): HttpResponse|JsonResponse
    {
        try {
            $this->invitations->revoke($invitation, $this->actor($user));
        } catch (InvitationNotRevocable) {
            abort(404);
        } catch (PrivilegeEscalation) {
            return response()->json(['message' => 'owner_lacks_permission'], 403);
        }

        return response()->noContent();
    }

    private function actor(User $user): Actor
    {
        // The token path signs in with a token only (ADR-0007).
        return Actor::token($user->currentAccessToken(), $user);
    }
}
