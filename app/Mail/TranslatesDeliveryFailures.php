<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\SentMessage;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * For every mailable of the application: a failure of the transport leaves
 * send() as MailDeliveryFailed, without the recipient, before it reaches the
 * queue and failed_jobs.exception (task 8a).
 */
trait TranslatesDeliveryFailures
{
    /**
     * @param  MailFactory|Mailer  $mailer
     */
    public function send($mailer): ?SentMessage
    {
        try {
            return parent::send($mailer);
        } catch (TransportExceptionInterface $e) {
            throw MailDeliveryFailed::from($e);
        }
    }
}
