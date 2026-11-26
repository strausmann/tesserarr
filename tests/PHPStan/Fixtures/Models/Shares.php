<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture of ModelBootRequestStateRule: analysed by PHPStan in the rule
// tests, never loaded.

namespace App\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        $tenant = session('tenant');
        static::addGlobalScope('tenant', fn (Builder $query) => $query->where('tenant', $tenant));
    }
}

final class Share extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        $owner = auth()->id();
        self::addGlobalScope('owner', fn (Builder $query) => $query->where('owner_id', $owner));
        self::addGlobalScope('visible', fn (Builder $query) => $query->where('owner_id', auth()->id()));
        self::addGlobalScope(new OwnerScope(Auth::id()));
        self::creating(function (self $share): void {
            $share->setAttribute('owner_id', request()->user()?->getAuthIdentifier());
        });
    }

    protected static function boot(): void
    {
        parent::boot();
        $ip = Request::ip();
        $flash = Session::get('flash');
    }

    public function ownedByCurrentUser(): bool
    {
        return $this->getAttribute('owner_id') === auth()->id();
    }
}

final class OwnerScope implements Scope
{
    private mixed $owner;

    public function __construct(mixed $owner = null)
    {
        $this->owner = $owner ?? auth()->id();
    }

    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('owner_id', $this->owner ?? auth()->id());
    }
}

final class NotAModel
{
    public static function booted(): mixed
    {
        return auth()->id();
    }
}
