<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';

    // The display name the operator set (general settings), "Tesserarr" until then.
    const name = $derived((page.props as { name?: string }).name ?? 'Tesserarr');
</script>

<AppLogoIcon class="size-8 shrink-0" />
<span class="truncate text-[20px] leading-tight font-bold">{name}</span>
