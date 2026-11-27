<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Actor;
use App\Audit\AuditEntry;
use App\Audit\AuditPresenter;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\SettingsRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit log page (Audit.dc.html): newest first, as many per page as the
 * general setting ui.page_size says (50 until it is set), with
 * links to newer and older entries (keyset on the id, stable while entries
 * arrive), filtered by period, person, action and origin. People are named
 * by their current username or display name, looked up by id when shown
 * (AuditPresenter); the log itself holds no names and no addresses.
 */
final class AuditLogController extends Controller
{
    private const PERIODS = ['24h' => 1, '7d' => 7, '30d' => 30];

    private const SOURCES = [
        'ui' => [Actor::USER, Actor::GUEST],
        'cli' => [Actor::CLI],
        'api' => [Actor::TOKEN, Actor::SATELLITE],
    ];

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly AuditPresenter $presenter,
    ) {}

    public function __invoke(Request $request): Response
    {
        $perPage = (int) $this->settings->value(SettingKey::PageSize);
        $filters = $request->validate([
            'period' => ['sometimes', 'string', 'in:'.implode(',', array_keys(self::PERIODS))],
            'source' => ['sometimes', 'string', 'in:all,'.implode(',', array_keys(self::SOURCES))],
            'who' => ['sometimes', 'nullable', 'string', 'regex:/\A(user:\d+|cli|satellite:\d+)\z/'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'before' => ['sometimes', 'integer', 'min:1'],
            'after' => ['sometimes', 'integer', 'min:1'],
        ]);
        $period = (string) ($filters['period'] ?? '7d');
        $source = (string) ($filters['source'] ?? 'all');
        $who = isset($filters['who']) ? (string) $filters['who'] : null;
        $q = isset($filters['q']) ? trim((string) $filters['q']) : null;

        $filtered = fn (): Builder => $this->filtered($period, $source, $who, $q);

        if (isset($filters['after'])) {
            $page = $filtered()->where('id', '>', (int) $filters['after'])->orderBy('id')->limit($perPage)->get()->reverse()->values();
        } else {
            $page = $filtered()->when(isset($filters['before']), fn (Builder $b) => $b->where('id', '<', (int) $filters['before']))
                ->orderByDesc('id')->limit($perPage)->get();
        }

        $query = array_filter(['period' => $period, 'source' => $source, 'who' => $who, 'q' => $q], fn ($v) => $v !== null && $v !== '');
        $first = $page->first();
        $last = $page->last();

        return Inertia::render('admin/Audit', [
            'entries' => $this->presenter->present($page),
            'total' => $filtered()->count(),
            'newer' => $first !== null && $filtered()->where('id', '>', $first->id)->exists()
                ? route('audit.index', [...$query, 'after' => $first->id]) : null,
            'older' => $last !== null && $filtered()->where('id', '<', $last->id)->exists()
                ? route('audit.index', [...$query, 'before' => $last->id]) : null,
            'filters' => ['period' => $period, 'source' => $source, 'who' => $who, 'q' => $q],
            'people' => User::query()->orderBy('username')->get(['id', 'username', 'display_name'])
                ->map(fn (User $user) => ['value' => 'user:'.$user->id, 'label' => $user->shownName()])->values()->all(),
        ]);
    }

    /** @return Builder<AuditEntry> */
    private function filtered(string $period, string $source, ?string $who, ?string $q): Builder
    {
        return AuditEntry::query()
            ->where('occurred_at', '>=', Date::now()->subDays(self::PERIODS[$period]))
            ->when($source !== 'all', fn (Builder $b) => $b->whereIn('actor_type', self::SOURCES[$source]))
            ->when($who !== null, function (Builder $b) use ($who) {
                [$type, $id] = array_pad(explode(':', (string) $who, 2), 2, null);
                match ($type) {
                    // An account acts in the interface and through its tokens.
                    'user' => $b->whereIn('actor_type', [Actor::USER, Actor::TOKEN])->where('actor_id', (int) $id),
                    'satellite' => $b->where('actor_type', Actor::SATELLITE)->where('actor_id', (int) $id),
                    default => $b->where('actor_type', Actor::CLI),
                };
            })
            ->when($q !== null && $q !== '', fn (Builder $b) => $b->where('action', 'ilike', '%'.addcslashes((string) $q, '%_\\').'%'));
    }
}
