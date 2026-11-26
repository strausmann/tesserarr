<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    /**
     * The mark: four tiles in ink, amber and stamp blue (Main.dc.html,
     * public/logo.svg). Drawn with the colour roles, so it follows the theme.
     */
    let {
        class: className = '',
        ...rest
    }: {
        class?: string;
        [key: string]: unknown;
    } = $props();
</script>

<svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 48 48"
    aria-hidden="true"
    class={className}
    {...rest}
>
    <rect x="5" y="5" width="18" height="18" rx="3" fill="var(--tr-ink)" />
    <rect x="27" y="3" width="18" height="18" rx="3" fill="var(--tr-accent)" />
    <rect x="3" y="27" width="18" height="18" rx="3" fill="var(--tr-link)" />
    <rect x="25" y="25" width="18" height="18" rx="3" fill="var(--tr-ink)" />
</svg>
