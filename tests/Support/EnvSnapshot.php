<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Support;

use Illuminate\Support\Env;

/**
 * Sets process environment variables for one test and puts the previous
 * values back afterwards.
 *
 * Laravel reads variables from $_SERVER, $_ENV and getenv(), in that order,
 * so all three are written. Laravel's environment repository is reset as
 * well: it remembers which variables an earlier boot loaded from .env and
 * would let the next refreshApplication() overwrite our value with the one
 * from .env again. Environment state is process-wide: a value that
 * leaks out of one test changes the next one, so every test that sets a
 * variable restores it in afterEach().
 */
final class EnvSnapshot
{
    /** @var array<string, array{server: mixed, env: mixed, getenv: string|false}> */
    private array $saved = [];

    /**
     * @param  array<string, string|null>  $values  null unsets the variable
     */
    public function set(array $values): self
    {
        foreach ($values as $name => $value) {
            if (! array_key_exists($name, $this->saved)) {
                $this->saved[$name] = [
                    'server' => $_SERVER[$name] ?? null,
                    'env' => $_ENV[$name] ?? null,
                    'getenv' => getenv($name),
                ];
            }

            self::write($name, $value);
        }

        Env::enablePutenv();

        return $this;
    }

    public function restore(): void
    {
        foreach ($this->saved as $name => $previous) {
            self::write($name, $previous['getenv'] === false ? null : $previous['getenv']);

            if ($previous['server'] === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $previous['server'];
            }

            if ($previous['env'] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $previous['env'];
            }
        }

        $this->saved = [];
        Env::enablePutenv();
    }

    private static function write(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);

            return;
        }

        putenv("{$name}={$value}");
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
    }
}
