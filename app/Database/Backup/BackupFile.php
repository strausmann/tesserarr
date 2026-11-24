<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Database\Backup;

/**
 * A written backup: where it lies and how large it is. Never its content.
 */
final readonly class BackupFile
{
    public function __construct(
        public string $path,
        public int $bytes,
    ) {}

    public function name(): string
    {
        return basename($this->path);
    }
}
