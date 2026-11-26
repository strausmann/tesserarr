<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Ui;

/**
 * Every section of the interface that a user can collapse
 * (resources/js/components/ui/Collapsible.svelte). Its state is stored per
 * user in `users.ui_collapsed` (`ui.collapsed`); a key that is not listed here
 * is rejected, so the map cannot grow with whatever a client sends.
 *
 * The value is the key the page passes to Collapsible. A view that adds a
 * collapsible section adds its case here in the same pull request.
 */
enum CollapsibleSection: string
{
    /** "Braucht Aufmerksamkeit" on the dashboard (0.2.0). */
    case DashboardAttention = 'dashboard.attention';

    /**
     * The stored map, reduced to the sections that still exist.
     *
     * @param  array<array-key, mixed>  $stored
     * @return array<string, bool>
     */
    public static function known(array $stored): array
    {
        $known = [];
        foreach (self::cases() as $section) {
            if (is_bool($stored[$section->value] ?? null)) {
                $known[$section->value] = $stored[$section->value];
            }
        }

        return $known;
    }
}
