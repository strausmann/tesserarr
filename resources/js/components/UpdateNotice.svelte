<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import RotateCw from '@lucide/svelte/icons/rotate-cw';
    import { t } from '@/lib/i18n';
    import type { UpdateNotice } from '@/lib/updates.svelte';
    import { notice as appNotice } from '@/lib/updates.svelte';

    /**
     * "New version available" (lib/updates.svelte.ts, Dashboard.dc.html):
     * `box` in the foot of the navigation, stays until the page runs the new
     * version; `toast` at the bottom right, closed by "Later" for that
     * version. Neither reloads by itself. The version comes from a response
     * header and is only ever shown as text.
     */
    let {
        variant,
        notice = appNotice,
    }: { variant: 'box' | 'toast'; notice?: UpdateNotice } = $props();

    const state = $derived(notice.state);
</script>

{#if variant === 'box' && state.waiting}
    <div class="tr-update-box">
        <p class="tr-update-title">
            <RotateCw class="size-[18px] shrink-0" aria-hidden="true" />
            <span>{t('update.ready', { version: state.version ?? '' })}</span>
        </p>
        <p class="tr-update-hint">{t('update.ready_hint')}</p>
        <button
            type="button"
            class="tr-update-reload tr-update-reload--box"
            onclick={() => notice.reload()}>{t('update.reload')}</button
        >
    </div>
{:else if variant === 'toast' && state.toastOpen}
    <section class="tr-update-toast" aria-label={t('update.region')}>
        <div role="status" class="tr-update-message">
            <svg
                width="12"
                height="12"
                viewBox="0 0 12 12"
                aria-hidden="true"
                class="tr-update-mark"
                ><path d="M6 1 L11 11 L1 11 Z" fill="currentColor"></path></svg
            >
            <span class="flex min-w-0 flex-col">
                <span class="font-bold">{t('update.available')}</span>
                <span class="tr-update-version"
                    >{t('update.version', { version: state.version ?? '' })}</span
                >
            </span>
        </div>
        <div class="tr-update-actions">
            <button
                type="button"
                class="tr-update-reload"
                onclick={() => notice.reload()}>{t('update.reload')}</button
            >
            <button
                type="button"
                class="tr-update-later"
                onclick={() => notice.dismiss()}>{t('update.later')}</button
            >
        </div>
    </section>
{/if}
