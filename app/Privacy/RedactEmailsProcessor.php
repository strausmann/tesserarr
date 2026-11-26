<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Stringable;
use Throwable;

/**
 * Keeps addresses out of every log: replaces each address (EmailNormalizer::
 * PATTERN) in the message, the context and the extra fields, at every depth,
 * by "[email]".
 *
 * An exception in the context is replaced by a redacted description before a
 * formatter sees it: Laravel hands the exception over as an object in
 * context['exception'], and the formatter would print its message, the
 * messages of the previous exceptions and the arguments of the trace. The
 * description has class, code, the redacted message, file, line, the
 * previous exception (the same way) and the trace as "file:line" only, with
 * no arguments. The image sets zend.exception_ignore_args as well (task 1).
 *
 * Put on every channel by RedactingTap (config/logging.php).
 */
final class RedactEmailsProcessor implements ProcessorInterface
{
    public const REPLACEMENT = '[email]';

    private const MAX_PREVIOUS = 10;

    public function __invoke(LogRecord $record): LogRecord
    {
        /** @var array<mixed> $context */
        $context = self::value($record->context);
        /** @var array<mixed> $extra */
        $extra = self::value($record->extra);

        return $record->with(
            message: self::text($record->message),
            context: $context,
            extra: $extra,
        );
    }

    private static function value(mixed $value, int $depth = 0): mixed
    {
        return match (true) {
            is_string($value) => self::text($value),
            $value instanceof Throwable => self::exception($value),
            is_array($value) => $depth > 16 ? '[…]' : array_map(fn (mixed $v) => self::value($v, $depth + 1), $value),
            $value instanceof Stringable => self::text((string) $value),
            default => $value,
        };
    }

    private static function text(string $text): string
    {
        return (string) preg_replace(EmailNormalizer::PATTERN, self::REPLACEMENT, $text);
    }

    /** @return array<string, mixed> */
    private static function exception(Throwable $e, int $depth = 0): array
    {
        $previous = $e->getPrevious();

        return [
            'class' => $e::class,
            'code' => $e->getCode(),
            'message' => self::text($e->getMessage()),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'previous' => $previous === null || $depth >= self::MAX_PREVIOUS ? null : self::exception($previous, $depth + 1),
            'trace' => array_map(
                fn (array $frame) => isset($frame['file']) ? $frame['file'].':'.($frame['line'] ?? 0) : '[internal]',
                $e->getTrace(),
            ),
        ];
    }
}
