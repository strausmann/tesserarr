<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The single-use link that confirms an account address (task 8a), sent to the
 * new address. Queued encrypted (ShouldBeEncrypted): the job holds the
 * recipient and the link. Transport and PGP/MIME come with task 10a.
 */
final class ConfirmEmailMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels, TranslatesDeliveryFailures;

    public function __construct(
        public readonly string $url,
        public readonly int $minutes,
        public readonly string $username,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.confirm.subject'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.confirm-email');
    }
}
