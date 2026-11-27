<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\App;

use App\Audit\Actor;
use App\Authorization\Permission;
use App\Authorization\PrivilegeEscalation;
use App\Http\Controllers\Controller;
use App\Invitations\InvitationKind;
use App\Invitations\InvitationNotRevocable;
use App\Invitations\InvitationRequest;
use App\Invitations\InvitationService;
use App\Invitations\InvitationView;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Invitations from the interface (session path): the dialog on the user list
 * (Users.dc.html) issues access invitations, the user detail a recovery
 * invitation for that account (`user`, which also needs `users.manage`
 * and every permission the account holds). The answer carries the link once,
 * with `Cache-Control: no-store`.
 */
final class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function store(Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $request->validate(['user' => ['nullable', 'integer', 'exists:users,id']]);
        [$ttl, $note] = InvitationRequest::read($request);
        $for = $request->filled('user') ? User::query()->findOrFail((int) $request->integer('user')) : null;

        try {
            $issued = $this->invitations->issue(
                Actor::user($actor),
                $for === null ? InvitationKind::Access : InvitationKind::Recovery,
                $for,
                $ttl,
                $note,
            );
        } catch (PrivilegeEscalation $refused) {
            return response()->json([
                'message' => 'privilege_escalation',
                'refused' => Permission::values($refused->permissions),
            ], 403);
        }

        return response()->json([
            'invitation' => InvitationView::of($issued->invitation),
            'url' => $issued->url,
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    public function destroy(int $invitation, #[CurrentUser] User $actor): Response|JsonResponse
    {
        try {
            $this->invitations->revoke($invitation, Actor::user($actor));
        } catch (InvitationNotRevocable) {
            abort(404);
        } catch (PrivilegeEscalation) {
            return response()->json(['message' => 'privilege_escalation'], 403);
        }

        return response()->noContent();
    }
}
