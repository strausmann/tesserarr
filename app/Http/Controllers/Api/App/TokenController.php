<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\App;

use App\Audit\Actor;
use App\Authorization\Permission;
use App\Authorization\PrivilegeEscalation;
use App\Authorization\TokenPreset;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tokens\InvalidTokenRequest;
use App\Tokens\TokenIssuer;
use App\Tokens\TokenRevoker;
use App\Tokens\TokenView;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issuing and revoking tokens from the interface (session path).
 *
 * Issuing (`tokens.manage_own`, behind a fresh password confirmation, K13)
 * always issues for the signed-in account. The answer carries the token once,
 * with `Cache-Control: no-store`; it is not flashed, not stored in the
 * session and not logged.
 */
final class TokenController extends Controller
{
    /** The expiry choices of the form, in days; `never` for none. */
    public const EXPIRY_CHOICES = ['30', '90', '365', 'never'];

    public function __construct(
        private readonly TokenIssuer $issuer,
        private readonly TokenRevoker $revoker,
    ) {}

    public function store(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.TokenIssuer::NAME_MAX],
            'preset' => ['nullable', 'string', Rule::enum(TokenPreset::class)],
            'permissions' => ['required_without:preset', 'array', 'max:'.count(Permission::cases())],
            'permissions.*' => ['string', Rule::enum(Permission::class)],
            'expires' => ['required', 'string', Rule::in(self::EXPIRY_CHOICES)],
        ]);

        /** @var list<string> $chosen */
        $chosen = array_values($validated['permissions'] ?? []);
        $permissions = $chosen !== []
            ? Permission::fromValues($chosen)
            : TokenPreset::from((string) $validated['preset'])->permissions();

        $expires = (string) $validated['expires'];
        $expiresAt = $expires === 'never' ? null : CarbonImmutable::now()->addDays((int) $expires);

        try {
            $new = $this->issuer->issue($user, Actor::user($user), (string) $validated['name'], $permissions, $expiresAt);
        } catch (PrivilegeEscalation $refused) {
            return response()->json([
                'message' => 'privilege_escalation',
                'refused' => Permission::values($refused->permissions),
            ], 403);
        } catch (InvalidTokenRequest $invalid) {
            throw ValidationException::withMessages([
                $invalid->reason === InvalidTokenRequest::EXPIRY ? 'expires' : $invalid->reason => __('tokens.invalid.'.$invalid->reason),
            ]);
        }

        return response()->json([
            'token' => TokenView::of($new->accessToken, $user),
            'plain_text' => $new->plainTextToken,
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    /** Revokes one of the own tokens (`tokens.manage_own`); another account's is 404. */
    public function destroy(int $token, #[CurrentUser] User $user): Response
    {
        $found = PersonalAccessToken::query()
            ->whereKey($token)
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->first();

        return $this->revoke($found, $user);
    }

    /** Revokes any token (`tokens.manage_all`). */
    public function destroyAny(int $token, #[CurrentUser] User $user): Response
    {
        return $this->revoke(PersonalAccessToken::query()->find($token), $user);
    }

    private function revoke(?PersonalAccessToken $token, User $user): Response
    {
        if ($token === null) {
            abort(404);
        }

        try {
            $this->revoker->revoke($token, Actor::user($user));
        } catch (ModelNotFoundException) {
            abort(404);
        }

        return response()->noContent();
    }
}
