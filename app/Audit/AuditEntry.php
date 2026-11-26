<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row of the append-only audit log. The database refuses UPDATE and
 * DELETE on the table (trigger audit_entries_append_only); write through
 * AuditLogger only.
 *
 * @property int $id
 * @property Carbon $occurred_at
 * @property string $action
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed> $context
 * @property AuditOutcome $outcome
 */
final class AuditEntry extends Model
{
    public const CREATED_AT = 'occurred_at';

    public const UPDATED_AT = null;

    protected $table = 'audit_entries';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'actor_id' => 'integer',
            'subject_id' => 'integer',
            'context' => 'array',
            'outcome' => AuditOutcome::class,
        ];
    }
}
