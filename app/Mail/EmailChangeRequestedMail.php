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
 * Tells the confirmed address of an account that another address was entered
 * for it (task 8a). The old address stays valid until the new one is
 * confirmed; the mail names neither address. Queued encrypted.
 */
final class EmailChangeRequestedMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels, TranslatesDeliveryFailures;

    public function __construct(public readonly string $username) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.change.subject'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.email-change-requested');
    }
}
