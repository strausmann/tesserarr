<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

use App\Settings\Settings;
use Illuminate\Contracts\Config\Repository;

/**
 * Whether an account address can be entered at all (pre-flight K29, Spec 9.2):
 * the operator allowed it (setting `email.allowed`, off until set) AND a mail
 * transport is configured, because an address only counts once its link was
 * opened. Without either, accounts sign in by username only.
 *
 * "A transport" is the default mailer, if it is defined and not the `log`
 * mailer: the log mailer writes the message, address and link included, into
 * the log, so it is defined only in the local environment (config/mail.php).
 */
final class AccountEmailPolicy
{
    public const SETTING = 'email.allowed';

    public function __construct(
        private readonly Settings $settings,
        private readonly Repository $config,
    ) {}

    /**
     * null when an address can be entered, else why not.
     *
     * @return 'switched_off'|'no_transport'|null
     */
    public function refusal(): ?string
    {
        if ($this->settings->get(self::SETTING, false) !== true) {
            return 'switched_off';
        }

        return $this->hasTransport() ? null : 'no_transport';
    }

    public function allowsEntry(): bool
    {
        return $this->refusal() === null;
    }

    public function hasTransport(): bool
    {
        $name = $this->config->get('mail.default');
        if (! is_string($name) || $name === '') {
            return false;
        }

        $mailer = $this->config->get("mail.mailers.{$name}");

        return is_array($mailer) && ($mailer['transport'] ?? null) !== 'log';
    }
}
