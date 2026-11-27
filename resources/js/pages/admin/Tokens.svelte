<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    export const layout = { title: 'nav.tokens' };
</script>

<script lang="ts">
    import type { ComponentProps } from 'svelte';
    import TokenManager from '@/components/tokens/TokenManager.svelte';

    // Every API token with its owner (Tokens.dc.html, tokens.manage_all).
    let props: Omit<ComponentProps<typeof TokenManager>, 'scope'> = $props();
</script>

<TokenManager scope="all" {...props} />
