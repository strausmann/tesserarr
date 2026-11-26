<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Http\Ui\CollapsibleSection;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Stores whether the signed-in user collapsed a section (`ui.collapsed`).
 *
 * Only the one key is written, with jsonb_set in a single statement: two
 * sections toggled at once from two tabs cannot overwrite each other. A key
 * outside CollapsibleSection is rejected with 422.
 */
final class CollapsedSectionController extends Controller
{
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $valid = $request->validate([
            'section' => ['required', 'string', Rule::enum(CollapsibleSection::class)],
            'collapsed' => ['required', 'boolean'],
        ]);

        $section = CollapsibleSection::from($valid['section']);

        // The key holds a dot, which the query builder's `column->key` syntax
        // would read as a path: the key goes in as a bound text array instead.
        DB::update(
            'update users set ui_collapsed = jsonb_set(ui_collapsed, ARRAY[?]::text[], to_jsonb(?::boolean)) where id = ?',
            [$section->value, (bool) $valid['collapsed'], $user->getKey()],
        );

        return response()->noContent();
    }
}
