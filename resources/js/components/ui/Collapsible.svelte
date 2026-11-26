<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import ChevronDown from '@lucide/svelte/icons/chevron-down';
    import type { Snippet } from 'svelte';
    import { onDestroy } from 'svelte';
    import { COLLAPSED_SAVE_DELAY_MS, isCollapsed, latestWins, saveCollapsed } from '@/lib/collapsed';

    /**
     * A section the user can fold away. The heading is the switch
     * (aria-expanded, at least 44 px high); folded, the section shows only
     * `summary`. The state is stored per user on the server (`ui.collapsed`,
     * every device) shortly after it is toggled, without a reload; quick
     * toggles send only the last state, one request at a time; without a
     * stored value the page's default holds. `section` must be a case of
     * App\Http\Ui\CollapsibleSection, the server refuses other keys.
     */
    let {
        section,
        title,
        summary = '',
        collapsedByDefault = false,
        level = 2,
        stored = undefined,
        save = saveCollapsed,
        children,
    }: {
        section: string;
        title: string;
        summary?: string;
        collapsedByDefault?: boolean;
        level?: 2 | 3;
        /** The stored map; the page prop `ui.collapsed` when not given. */
        stored?: Record<string, boolean>;
        save?: (section: string, collapsed: boolean) => Promise<boolean>;
        children?: Snippet;
    } = $props();

    const initial = () =>
        isCollapsed(
            stored ??
                (page.props as { ui?: { collapsed?: Record<string, boolean> } })
                    .ui?.collapsed,
            section,
            collapsedByDefault,
        );
    let collapsed = $state(initial());
    const bodyId = $derived(`tr-collapsible-${section.replaceAll('.', '-')}`);

    // svelte-ignore state_referenced_locally
    const store = latestWins((value: boolean) => save(section, value), COLLAPSED_SAVE_DELAY_MS);
    onDestroy(() => store.flush());

    function toggle(): void {
        collapsed = !collapsed;
        store.set(collapsed);
    }
</script>

<section class="tr-collapsible" aria-labelledby="{bodyId}-heading">
    <svelte:element this={`h${level}`} id="{bodyId}-heading" class="tr-collapsible-heading">
        <button
            type="button"
            class="tr-collapsible-switch"
            aria-expanded={!collapsed}
            aria-controls={bodyId}
            onclick={toggle}
        >
            <span>{title}</span>
            <ChevronDown
                class="tr-collapsible-chevron size-5"
                aria-hidden="true"
            />
        </button>
    </svelte:element>
    {#if collapsed && summary}
        <p class="tr-collapsible-summary">{summary}</p>
    {/if}
    <div id={bodyId} hidden={collapsed}>
        {@render children?.()}
    </div>
</section>
