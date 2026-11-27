<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    export const layout = { title: 'nav.my_tokens' };
</script>

<script lang="ts">
    import type { ComponentProps } from 'svelte';
    import TokenManager from '@/components/tokens/TokenManager.svelte';

    // The own API tokens (Tokens.dc.html, tokens.manage_own).
    let props: Omit<ComponentProps<typeof TokenManager>, 'scope'> = $props();
</script>

<TokenManager scope="own" {...props} />
