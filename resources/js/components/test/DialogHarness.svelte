<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    // Test page for Dialog.test.ts: an opener, the dialog with a field, a
    // list that handles Escape itself, and two buttons in the footer.
    import Dialog from '@/components/ui/Dialog.svelte';

    let {
        variant = 'center',
        listOpen = false,
    }: { variant?: 'center' | 'drawer'; listOpen?: boolean } = $props();

    let open = $state(false);
    // svelte-ignore state_referenced_locally
    let list = $state(listOpen);
    let closed = $state(0);
</script>

<button type="button" id="opener" onclick={() => (open = true)}>Open</button>
<button type="button" id="outside">Outside</button>
<p id="closed">{closed}</p>

<Dialog
    bind:open
    title="Add share"
    {variant}
    onclose={() => closed++}
>
    <input
        id="field"
        aria-label="Folder"
        onkeydown={(e) => {
            if (e.key === 'Escape' && list) {
                list = false;
                e.preventDefault();
            }
        }}
    />
    {#if list}<p id="list">list</p>{/if}
    {#snippet footer()}
        <button type="button" id="cancel">Cancel</button>
        <button type="button" id="save">Save</button>
    {/snippet}
</Dialog>
