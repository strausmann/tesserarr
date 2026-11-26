<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Audit;

/** Whether the recorded action was carried out or refused (ADR-0009: refusals are their own level). */
enum AuditOutcome: string
{
    case Done = 'done';
    case Denied = 'denied';
}
