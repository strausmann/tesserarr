<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Support;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Makes text from the database safe to print in a terminal: control and
 * formatting characters become visible escapes (\x1b, \u{202e}), so a
 * display name cannot clear the screen, move the cursor or reorder the line,
 * and console style tags are escaped, so "<error>" prints as itself.
 */
final class TerminalText
{
    public static function safe(string $text): string
    {
        $visible = (string) preg_replace_callback('/\p{C}/u', function (array $match): string {
            $code = mb_ord($match[0]);

            return $code < 0x100 ? sprintf('\x%02x', $code) : sprintf('\u{%x}', $code);
        }, $text);

        return OutputFormatter::escape($visible);
    }
}
