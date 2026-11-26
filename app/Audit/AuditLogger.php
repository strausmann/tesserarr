<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Writes the audit log (ADR-0009): what was done, by whom (kind and id, never
 * an e-mail address), to which subject, with a small context and whether it
 * was carried out or refused.
 *
 * A context key that names a secret stops the entry with SecretInAuditContext
 * before anything is written; the check runs on every level of a nested
 * context. The value itself is never inspected or repeated.
 */
final class AuditLogger
{
    /**
     * Fragments of a key, compared without case, underscores, dashes or dots.
     * A key that contains one of them is refused.
     */
    private const SECRET_KEY_FRAGMENTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'apikey',
        'authorization',
        'bearer',
        'cookie',
        'privatekey',
        'recoverycode',
        'credential',
        'sessionid',
    ];

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws SecretInAuditContext
     */
    public function record(AuditAction $action, Actor $actor, ?Model $subject = null, array $context = [], AuditOutcome $outcome = AuditOutcome::Done): AuditEntry
    {
        self::assertNoSecretKeys($context);

        return AuditEntry::query()->create([
            'action' => $action->value,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'actor_label' => $actor->label,
            'subject_type' => $subject === null ? null : self::subjectType($subject),
            'subject_id' => $subject?->getKey(),
            'context' => $context,
            'outcome' => $outcome,
        ]);
    }

    /** The short name of a subject the log page can resolve (user, role). */
    public static function subjectType(Model $subject): string
    {
        return match (true) {
            $subject instanceof User => 'user',
            $subject instanceof Role => 'role',
            default => $subject->getMorphClass(),
        };
    }

    /**
     * @param  array<array-key, mixed>  $context
     *
     * @throws SecretInAuditContext
     */
    private static function assertNoSecretKeys(array $context, string $prefix = ''): void
    {
        foreach ($context as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $normalised = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $key));

            foreach (self::SECRET_KEY_FRAGMENTS as $fragment) {
                if (str_contains($normalised, $fragment)) {
                    throw new SecretInAuditContext($path);
                }
            }

            if (is_array($value)) {
                self::assertNoSecretKeys($value, $path);
            }
        }
    }
}
