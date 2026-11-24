<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Database\Backup;

/**
 * Writes a complete backup of the application database to one file.
 */
interface DatabaseDumper
{
    /**
     * @throws BackupFailed when no complete backup could be written
     */
    public function dump(string $path): void;
}
