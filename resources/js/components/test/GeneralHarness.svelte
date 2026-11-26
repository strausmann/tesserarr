<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import General from '@/pages/admin/settings/General.svelte';

    // Test harness: shows the settings page and lets a test hand it new
    // props, as an Inertia visit does after a save.
    let {
        initial,
        holder,
    }: {
        initial: Record<string, unknown>;
        holder: { set: (next: Record<string, unknown>) => void };
    } = $props();

    // svelte-ignore state_referenced_locally
    let current = $state(initial);
    // svelte-ignore state_referenced_locally
    holder.set = (next) => {
        current = next;
    };
</script>

<General {...(current as never)} />
