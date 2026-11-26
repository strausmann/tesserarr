<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;

/**
 * Laravel's log manager with the redaction on its emergency logger too. That
 * logger writes when a configured channel cannot be built, and it has no
 * `tap` of its own (LogManager::createEmergencyLogger reads only the path).
 * Every other channel gets the redaction from RedactingTap.
 */
final class RedactingLogManager extends LogManager
{
    protected function createEmergencyLogger()
    {
        $logger = parent::createEmergencyLogger();
        (new RedactingTap)($logger instanceof Logger ? $logger : new Logger($logger));

        return $logger;
    }
}
