<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;
use Throwable;

/**
 * A mail could not be delivered. It replaces the exception of the transport
 * before the queue sees it (TranslatesDeliveryFailures): failed_jobs keeps
 * the job's payload encrypted (ShouldBeEncrypted), but the column
 * `exception` in plain text, and an SMTP answer such as "550 <address>
 * rejected" carries the recipient. This exception names the class of the
 * failure and its code, nothing of the message, and has no previous
 * exception (its text would be written as well).
 */
final class MailDeliveryFailed extends RuntimeException
{
    public function __construct(public readonly string $failure, int $code = 0)
    {
        parent::__construct(sprintf('Mail delivery failed (%s, code %d); the transport message is withheld because it may name the recipient.', $failure, $code), $code);
    }

    public static function from(Throwable $e): self
    {
        return new self($e::class, (int) $e->getCode());
    }
}
