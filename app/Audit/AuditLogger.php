<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Audit;

use App\Models\User;
use App\Privacy\EmailNormalizer;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;

/**
 * Writes the audit log (ADR-0009): what was done, by whom (kind and id, never
 * an e-mail address), to which subject, with a small context and whether it
 * was carried out or refused.
 *
 * A context key that names a secret stops the entry with SecretInAuditContext
 * before anything is written; the check runs on every level of a nested
 * context. The value itself is never inspected or repeated.
 *
 * A context value (or key) that looks like an e-mail address stops it with
 * PiiInAuditContext (task 8a): people are named by id, and the names are
 * looked up when the log is shown (AuditPresenter). Role and token names are
 * labels somebody chose and may look like an address ("ops@example.org"):
 * under the keys of OPERATOR_LABELS such a part is written as "[email]"
 * instead, so the role or the token stays manageable.
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
     * Top-level context keys that carry a name somebody chose: a role name
     * (or a list of them), the name of an API token.
     */
    private const OPERATOR_LABELS = ['role', 'previous', 'label'];

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws SecretInAuditContext
     * @throws PiiInAuditContext
     */
    public function record(AuditAction $action, Actor $actor, ?Model $subject = null, array $context = [], AuditOutcome $outcome = AuditOutcome::Done): AuditEntry
    {
        self::assertNoSecretKeys($context);
        foreach (self::OPERATOR_LABELS as $key) {
            if (is_string($context[$key] ?? null)) {
                $context[$key] = EmailNormalizer::redact($context[$key]);
            } elseif (is_array($context[$key] ?? null)) {
                $context[$key] = array_map(fn ($name) => is_string($name) ? EmailNormalizer::redact($name) : $name, $context[$key]);
            }
        }
        self::assertNoAddresses($context);

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
            $subject instanceof PersonalAccessToken => 'token',
            default => $subject->getMorphClass(),
        };
    }

    /**
     * @param  array<array-key, mixed>  $context
     *
     * @throws PiiInAuditContext
     */
    private static function assertNoAddresses(array $context, string $prefix = ''): void
    {
        foreach ($context as $key => $value) {
            // A key that is itself an address is named as "[key]".
            $name = EmailNormalizer::containsAddress((string) $key) ? '[key]' : (string) $key;
            $path = $prefix === '' ? $name : $prefix.'.'.$name;

            if ($name === '[key]' || (is_string($value) && EmailNormalizer::containsAddress($value))) {
                throw new PiiInAuditContext($path);
            }

            if (is_array($value)) {
                self::assertNoAddresses($value, $path);
            }
        }
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
