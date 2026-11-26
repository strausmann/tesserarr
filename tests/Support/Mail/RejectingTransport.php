<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A mail transport that answers like an SMTP server refusing the recipient:
 * the message of the exception names the address, as real servers do.
 */
final class RejectingTransport implements TransportInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $to = $envelope?->getRecipients()[0]->getAddress() ?? 'unknown';

        throw new TransportException("Expected response code \"250\" but got code \"550\", with message \"550 5.1.1 <{$to}>: Recipient address rejected\".", 550);
    }

    public function __toString(): string
    {
        return 'rejecting://';
    }
}
