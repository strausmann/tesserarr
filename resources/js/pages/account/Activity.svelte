<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    export const layout = { title: 'account.activity.title' };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import ActivityList from '@/components/account/ActivityList.svelte';
    import type { ActivityEntry } from '@/components/account/ActivityList.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import EmptyState from '@/components/EmptyState.svelte';
    import { t } from '@/lib/i18n';

    // The own activity (task 8a): the latest log entries in which the account
    // acted or was acted on, newest first. Names are looked up when shown.
    let { entries }: { entries: ActivityEntry[] } = $props();
</script>

<AppHead title={t('account.activity.title')} />

<div class="tr-page">
    <header class="tr-page-header">
        <div>
            <h1 class="tr-page-title">{t('account.activity.title')}</h1>
            <p class="tr-lead">{t('account.activity.lead')}</p>
        </div>
        <Link href="/account" class="tr-button">{t('account.back')}</Link>
    </header>

    <section aria-labelledby="h-entries" class="tr-panel">
        <h2 id="h-entries" class="tr-panel-title">{t('account.activity.entries')}</h2>
        {#if entries.length === 0}
            <EmptyState title={t('account.activity.empty.title')} body={t('account.activity.empty.body')} />
        {:else}
            <ActivityList {entries} label={t('account.activity.entries')} />
        {/if}
    </section>
</div>
