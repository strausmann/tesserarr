<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture of SingletonStateRule: services that keep state after they were
// built. Analysed by PHPStan in the rule tests, never loaded.

namespace App\Fixtures\SingletonState;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\ServiceProvider;

final class MemoGreeter
{
    private ?string $name = null;

    /** @var list<string> */
    private array $seen = [];

    public function __construct()
    {
        $this->name = null;
    }

    public function greet(string $name): string
    {
        $this->seen[] = $name;

        return 'Hello '.($this->name ??= $name);
    }
}

final class BuiltOnce
{
    private string $prefix;

    public function __construct()
    {
        $this->prefix = 'Hello ';
    }

    public function greet(string $name): string
    {
        $prefix = $this->prefix;

        return $prefix.$name;
    }
}

final class ScopedMemo
{
    private ?string $name = null;

    public function remember(string $name): void
    {
        $this->name = $name;
    }
}

final class ClosureMemo
{
    /** @var list<mixed> */
    private array $items = [];

    public function add(mixed $item): void
    {
        $this->items[] = $item;
    }
}

#[Singleton]
final class AttributeCounter
{
    private int $hits = 0;

    public function hit(): void
    {
        $this->hits++;
    }
}

final class NotBound
{
    private int $hits = 0;

    public function hit(): void
    {
        $this->hits++;
    }
}

abstract class BaseCache
{
    /** @var array<string, string> */
    protected array $cache = [];

    public function put(string $key, string $value): void
    {
        $this->cache[$key] = $value;
    }
}

final class InheritsCache extends BaseCache {}

final class Bindings extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MemoGreeter::class);
        $this->app->singleton(BuiltOnce::class);
        $this->app->scoped(ScopedMemo::class);
        $this->app->singleton('items', fn () => new ClosureMemo);
        $this->app->singleton(InheritsCache::class);
    }
}
