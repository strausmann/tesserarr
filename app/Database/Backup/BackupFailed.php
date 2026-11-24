<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Database\Backup;

use RuntimeException;

/**
 * The backup before a migration could not be written. Its message names the
 * cause (exit code, empty file), never data from the database.
 */
final class BackupFailed extends RuntimeException {}
