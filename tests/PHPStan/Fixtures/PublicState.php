<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture of NoMutablePublicPropertyRule: analysed by PHPStan in the rule
// tests, never loaded.

namespace App\Fixtures\PublicState;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;

final class InvitationService
{
    public ?string $currentUser = null;

    public function __construct(public string $tenant, public readonly string $locale) {}
}

final class Settings
{
    public readonly int $limit;

    protected ?string $cache = null;

    private array $seen = [];

    public function __construct()
    {
        $this->limit = 3;
    }
}

final readonly class BackupFile
{
    public function __construct(public string $path, public int $bytes) {}
}

final class Justified
{
    // worker-safe: a per-call value object, built and thrown away inside one request
    public string $label = '';
}

final class Comment extends Model
{
    public $timestamps = false;
}

final class SendDigest implements ShouldQueue
{
    public function __construct(public int $userId) {}
}
