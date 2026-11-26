<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * The `tap` of every log channel (config/logging.php): adds
 * RedactEmailsProcessor. Monolog runs the processor pushed last first, so the
 * redaction runs before the placeholders of a message are filled in
 * (`replace_placeholders`) and before any formatter.
 *
 * The one logger without a tap is Laravel's emergency logger, which writes
 * only when a channel cannot be built at all (LogManager::createEmergencyLogger).
 */
final class RedactingTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactEmailsProcessor);
        }
    }
}
