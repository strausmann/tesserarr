<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Authorization\Authorizer;
use App\Authorization\Permission;
use App\Http\Controllers\Controller;
use App\Http\SelfService;
use App\Models\User;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\Contracts\HasAbilities;

/**
 * Who is calling: the owner of the token (GET /api/v1/me) or the signed-in
 * account (GET /api/v1/app/me), and what the request may do now. Self-service
 * on both paths; each path has its own route and its own sign-in check
 * (ADR-0007). The permissions are the Authorizer's answers, one per entry of
 * the catalogue, so a demotion shows at once.
 */
#[SelfService]
final class MeController extends Controller
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * Read the token's owner.
     *
     * Names the account the token belongs to and the permissions the token
     * may use now: those it carries and its owner still holds. Needs no
     * permission; any valid token may ask. Use it to check a token.
     */
    #[Endpoint(operationId: 'me.show')]
    #[Response(200, 'The owner of the token and what the token may do now', examples: [[
        'id' => 7,
        'username' => 'alice',
        'display_name' => 'Alice',
        'permissions' => ['catalog.browse'],
    ]])]
    #[Response(401, 'No token, an unknown, revoked or expired token, or the token of a locked account')]
    public function token(#[CurrentUser('sanctum')] User $user): JsonResponse
    {
        return $this->answer($user, $user->currentAccessToken());
    }

    /** The signed-in account of the interface (session path, not exported). */
    public function app(#[CurrentUser('web')] User $user): JsonResponse
    {
        return $this->answer($user, null);
    }

    private function answer(User $user, ?HasAbilities $credential): JsonResponse
    {
        $permissions = array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission) => $this->authorizer->allows($user, $credential, $permission)->allowed,
        ));

        /** @var list<string> $names the permission names, in catalogue order */
        $names = Permission::values($permissions);

        return response()->json([
            'id' => (int) $user->getKey(),
            'username' => (string) $user->username,
            'display_name' => (string) $user->display_name,
            'permissions' => $names,
        ]);
    }
}
