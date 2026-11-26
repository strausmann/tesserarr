<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts" generics="T">
    import SearchIcon from '@lucide/svelte/icons/search';
    import type { Snippet } from 'svelte';
    import Dialog from '@/components/ui/Dialog.svelte';
    import Field from '@/components/ui/Field.svelte';
    import { t } from '@/lib/i18n';
    import type { Lookup } from '@/lib/search.svelte';
    import { Search } from '@/lib/search.svelte';

    /**
     * Search on a phone: the magnifier opens the search as a dialog, the
     * results follow the field inside the dialog (so they are never under
     * the keyboard). Escape closes the list first, then the dialog; a tap on
     * a result keeps the focus in the field; the number of results and the
     * empty state are announced (aria-live). Waiting, dropping stale answers:
     * lib/search.svelte.ts.
     */
    let {
        label,
        lookup,
        item,
        onpick,
        open = $bindable(false),
    }: {
        label: string;
        lookup: Lookup<T>;
        item: Snippet<[T]>;
        onpick: (result: T) => void;
        open?: boolean;
    } = $props();

    // svelte-ignore state_referenced_locally
    const search = new Search<T>(lookup);
    let trigger = $state<HTMLButtonElement | null>(null);

    const count = $derived(
        search.results.length === 0
            ? t('search.count.none')
            : search.results.length === 1
              ? t('search.count.one')
              : t('search.count.other', { count: search.results.length }),
    );

    function keydown(event: KeyboardEvent): void {
        if (event.key === 'Escape' && search.escape()) {
            event.preventDefault();
        }
    }

    function pick(result: T): void {
        onpick(result);
        open = false;
    }
</script>

<button
    bind:this={trigger}
    type="button"
    class="tr-icon-button"
    aria-label={t('search.open')}
    aria-haspopup="dialog"
    onclick={() => (open = true)}
>
    <SearchIcon class="size-5" aria-hidden="true" />
</button>

<Dialog
    bind:open
    {label}
    returnFocus={trigger}
    onclose={() => search.stop()}
>
    <Field
        purpose="search"
        {label}
        hideLabel
        value={search.query}
        oninput={(event) => search.type(event.currentTarget.value)}
        onkeydown={keydown}
    />
    <p class="sr-only" aria-live="polite">
        {#if search.open && !search.loading}{search.results.length === 0
                ? t('search.empty', { query: search.query })
                : count}{/if}
    </p>
    {#if search.open}
        {#if search.results.length > 0}
            <ul class="tr-search-results" aria-label={label}>
                {#each search.results as result, i (i)}
                    <li>
                        <button
                            type="button"
                            class="tr-search-hit"
                            onpointerdown={(event) => event.preventDefault()}
                            onclick={() => pick(result)}
                        >
                            {@render item(result)}
                        </button>
                    </li>
                {/each}
            </ul>
        {:else if !search.loading}
            <p class="tr-search-empty">
                {t('search.empty', { query: search.query })}
            </p>
        {/if}
    {/if}
</Dialog>
