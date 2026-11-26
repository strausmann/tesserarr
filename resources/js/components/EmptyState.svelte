<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import type { Snippet } from 'svelte';

    /**
     * What a view shows while it has no data (Spec 16.3): one sentence that
     * says what is missing and, where the user can change that, the action
     * that does. Every view without data uses it.
     */
    let {
        title,
        body = '',
        action,
    }: {
        title: string;
        body?: string;
        action?: Snippet;
    } = $props();
</script>

<div class="tr-empty">
    <p class="tr-empty-title">{title}</p>
    {#if body}
        <p class="tr-empty-body">{body}</p>
    {/if}
    {#if action}
        <div class="tr-empty-action">{@render action()}</div>
    {/if}
</div>
