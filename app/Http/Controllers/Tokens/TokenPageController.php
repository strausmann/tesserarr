<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Tokens;

use App\Audit\Actor;
use App\Authorization\Authorizer;
use App\Authorization\Permission;
use App\Authorization\RoleGuard;
use App\Authorization\TokenPreset;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tokens\TokenView;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The token page (Tokens.dc.html, `tokens.manage_own`): the own tokens, and
 * with `tokens.manage_all` every token with its owner (admin/Tokens). The
 * form issues through the session API (Api\App\TokenController), so the new
 * token travels in one JSON answer and never in a page, the session or the
 * browser history.
 */
final class TokenPageController extends Controller
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly RoleGuard $guard,
    ) {}

    public function __invoke(#[CurrentUser] User $user): Response
    {
        $all = $this->allows($user, Permission::TokensManageAll);

        $query = PersonalAccessToken::query()->orderByDesc('id');
        if (! $all) {
            $query->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->getKey());
        }
        $tokens = $query->get(['id', 'tokenable_type', 'tokenable_id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at']);

        $owners = User::query()
            ->whereIn('id', $tokens->where('tokenable_type', $user->getMorphClass())->pluck('tokenable_id')->unique())
            ->get(['id', 'username', 'display_name'])
            ->keyBy('id');

        $grantable = $this->guard->grantableBy(Actor::user($user), $user);

        return Inertia::render($all ? 'admin/Tokens' : 'account/Tokens', [
            'tokens' => $tokens->map(fn (PersonalAccessToken $token) => TokenView::of(
                $token,
                $user,
                $owners->get((int) $token->getAttribute('tokenable_id')),
            ))->values()->all(),
            'grantable' => Permission::values($grantable),
            'administrative' => Permission::values(Permission::administrative()),
            'presets' => array_map(fn (TokenPreset $preset) => [
                'key' => $preset->value,
                'permissions' => Permission::values($preset->permissions()),
                'available' => array_filter($preset->permissions(), fn (Permission $p) => ! in_array($p, $grantable, true)) === [],
            ], TokenPreset::cases()),
            'can' => [
                'issue' => $grantable !== [],
                'revoke_any' => $all,
                'view_roles' => $this->allows($user, Permission::RolesManage),
            ],
            'base_url' => rtrim((string) config('app.url'), '/').'/api/v1/',
        ]);
    }

    private function allows(User $user, Permission $permission): bool
    {
        return $this->authorizer->allows($user, null, $permission)->allowed;
    }
}
