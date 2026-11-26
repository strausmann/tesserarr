<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Worker\Fixtures;

/**
 * A service with a per-instance cache. As a singleton resolved at boot it
 * leaks the first user's name to every later request; as a scoped binding
 * Octane builds it anew for every request.
 */
final class CachingGreeter
{
    private ?string $name = null;

    public function greet(?string $name): string
    {
        return 'Hello '.($this->name ??= $name ?? 'guest');
    }
}
