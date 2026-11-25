<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Base of the rule tests in this directory. It keeps PHPStan from disturbing
 * the rest of the suite:
 *
 * - On a cold cache PHPStan probes its directory listing with
 *   `@file_get_contents()`. PHPUnit drops that suppressed warning, the Pest
 *   printer reports it as a test warning. An empty listing file is a valid
 *   cold cache for PHPStan (it rebuilds the listing) and reads without one.
 * - With Larastan loaded, PHPStan boots a Laravel application, which installs
 *   its error and exception handlers. They are removed again, so the Laravel
 *   tests that run later find PHPUnit's handlers on top.
 *
 * @template TRule of Rule
 *
 * @extends RuleTestCase<TRule>
 */
abstract class WorkerRuleTestCase extends RuleTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        [$errorHandler, $exceptionHandler] = [self::errorHandler(), self::exceptionHandler()];
        $cache = self::getContainer()->getParameter('tmpDir').'/cache';
        self::restoreHandlers($errorHandler, $exceptionHandler);

        if (! is_dir($cache)) {
            mkdir($cache, 0777, true);
        }
        if (! is_file($cache.'/directory-listings.bin')) {
            touch($cache.'/directory-listings.bin');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->errorHandler = self::errorHandler();
        $this->exceptionHandler = self::exceptionHandler();
    }

    protected function tearDown(): void
    {
        self::restoreHandlers($this->errorHandler, $this->exceptionHandler);

        parent::tearDown();
    }

    /**
     * Pops handlers until the given ones are on top again (at most a few: one
     * application boot installs one of each).
     */
    private static function restoreHandlers(mixed $errorHandler, mixed $exceptionHandler): void
    {
        for ($i = 0; $i < 5 && self::errorHandler() !== $errorHandler; $i++) {
            restore_error_handler();
        }
        for ($i = 0; $i < 5 && self::exceptionHandler() !== $exceptionHandler; $i++) {
            restore_exception_handler();
        }
    }

    /** The current error handler, read by pushing a probe and popping it again. */
    private static function errorHandler(): mixed
    {
        $current = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $current;
    }

    private static function exceptionHandler(): mixed
    {
        $current = set_exception_handler(static fn () => null);
        restore_exception_handler();

        return $current;
    }
}
