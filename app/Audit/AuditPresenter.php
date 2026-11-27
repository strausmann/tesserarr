<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Shows audit entries (task 8a): the log stores who acted and on whom as kind
 * and id only; the names are looked up here, at the moment the entries are
 * shown, from the accounts as they are now (display name, or username).
 * Addresses are never resolved: the log page shows names, never an address.
 *
 * Used by the audit log page, the user detail page and "My account"
 * (the account's own activity).
 */
final class AuditPresenter
{
    /**
     * The entries an account took part in: it acted (in the interface or
     * through one of its tokens) or it was the subject.
     *
     * @return Builder<AuditEntry>
     */
    public static function involving(User $user): Builder
    {
        return AuditEntry::query()->where(function (Builder $q) use ($user) {
            $q->where(fn (Builder $acted) => $acted->whereIn('actor_type', [Actor::USER, Actor::TOKEN])->where('actor_id', $user->id))
                ->orWhere(fn (Builder $subject) => $subject->where('subject_type', 'user')->where('subject_id', $user->id));
        });
    }

    /**
     * @param  Collection<int, AuditEntry>  $entries
     * @return list<array{id: int, at: string, who: string, source: string, action: string, subject: string|null, outcome: string, context: array<string, mixed>}>
     */
    public function present(Collection $entries): array
    {
        $ids = $entries->filter(fn (AuditEntry $e) => in_array($e->actor_type, [Actor::USER, Actor::TOKEN], true))->pluck('actor_id')
            ->merge($entries->where('subject_type', 'user')->pluck('subject_id'))
            ->filter()->unique()->values();
        $names = User::query()->whereIn('id', $ids)->get(['id', 'username', 'display_name'])
            ->mapWithKeys(fn (User $user) => [$user->id => $user->shownName()]);

        return array_values($entries->map(fn (AuditEntry $entry) => [
            'id' => $entry->id,
            'at' => $entry->occurred_at->toIso8601String(),
            'who' => match ($entry->actor_type) {
                Actor::USER, Actor::TOKEN => (string) ($names[$entry->actor_id] ?? '#'.$entry->actor_id),
                default => (string) $entry->actor_label,
            },
            'source' => match ($entry->actor_type) {
                Actor::USER, Actor::GUEST => 'ui',
                Actor::CLI => 'cli',
                default => 'api',
            },
            'action' => $entry->action,
            'subject' => match ($entry->subject_type) {
                'user' => (string) ($names[$entry->subject_id] ?? '#'.$entry->subject_id),
                null => null,
                // A token is gone once revoked; its name stays in the entry.
                'token' => is_string($entry->context['label'] ?? null) ? $entry->context['label'] : '#'.$entry->subject_id,
                default => is_string($entry->context['role'] ?? null) ? $entry->context['role'] : '#'.$entry->subject_id,
            },
            'outcome' => $entry->outcome->value,
            'context' => $entry->context,
        ])->all());
    }
}
