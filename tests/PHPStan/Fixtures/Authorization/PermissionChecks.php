<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture of NoDirectPermissionCheckRule: analysed by PHPStan in the rule
// tests, never loaded.

namespace App\Fixtures\Authorization;

use App\Models\User;

final class ControllerLike
{
    public function checks(User $user): bool
    {
        return $user->hasPermissionTo('audit.view')
            || $user->hasRole('operator')
            || $user->hasAnyRole(['operator'])
            || $user->hasAnyPermission(['audit.view'])
            || $user->checkPermissionTo('audit.view');
    }

    public function fine(User $user): bool
    {
        return $user->getKey() !== null;
    }
}

final class AllowedChecker
{
    public function check(User $user): bool
    {
        return $user->hasRole('operator');
    }
}
