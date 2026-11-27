<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Invitations;

use App\Audit\Actor;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * An invitation as the interface shows it: field by field, never the model,
 * and never its hash.
 */
final class InvitationView
{
    /**
     * @param  Collection<int, string>|null  $names  shown names of the creators, by id (read when null)
     * @return array{id: int, kind: string, note: string|null, expires_at: string, created_at: string|null, created_by: string|null, via: string, for_user: string|null}
     */
    public static function of(Invitation $invitation, ?Collection $names = null): array
    {
        $by = $invitation->created_by_actor;
        $names ??= self::names(collect([$invitation]));
        $creatorId = in_array($by['type'], [Actor::USER, Actor::TOKEN], true) ? $by['id'] : null;

        return [
            'id' => (int) $invitation->id,
            'kind' => $invitation->kind->value,
            'note' => $invitation->note,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
            'created_by' => $creatorId === null ? null : (string) ($names[$creatorId] ?? '#'.$creatorId),
            'via' => match ($by['type']) {
                Actor::CLI => 'cli',
                Actor::TOKEN => 'api',
                default => 'ui',
            },
            'for_user' => $invitation->forUser?->username,
        ];
    }

    /**
     * The open invitations, newest first.
     *
     * @return list<array{id: int, kind: string, note: string|null, expires_at: string, created_at: string|null, created_by: string|null, via: string, for_user: string|null}>
     */
    public static function open(): array
    {
        $invitations = Invitation::query()->open()->with('forUser:id,username')->orderByDesc('id')->get();
        $names = self::names($invitations);

        return array_values($invitations->map(fn (Invitation $i) => self::of($i, $names))->all());
    }

    /**
     * @param  Collection<int, Invitation>  $invitations
     * @return Collection<int, string> shown names of the creators, by id
     */
    private static function names(Collection $invitations): Collection
    {
        $ids = $invitations->map(fn (Invitation $i) => $i->created_by_actor['id'] ?? null)->filter()->unique()->values();

        return User::query()->whereIn('id', $ids)->get(['id', 'username', 'display_name'])
            ->mapWithKeys(fn (User $u) => [$u->id => $u->shownName()]);
    }
}
