<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Audit;

/**
 * What the audit log records. The value is the key the log shows next to the
 * description ("role.update"). Later tasks add their actions here.
 */
enum AuditAction: string
{
    case RoleAssigned = 'role.assign';
    case RoleCreated = 'role.create';
    case RoleChanged = 'role.update';
    case RoleDeleted = 'role.delete';
    case UserLocked = 'user.lock';
    case UserUnlocked = 'user.unlock';
    case UserRenamed = 'user.rename';
    case SettingChanged = 'setting.update';
}
