<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    export const layout = { title: 'dashboard.title' };
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import EmptyState from '@/components/EmptyState.svelte';
    import { t } from '@/lib/i18n';

    // The operator's home (Dashboard.dc.html) before there is anything to
    // show: each section with its empty state. The setup checklist (task 22a),
    // the health of satellites and connections (18, 19), open invitations (10)
    // and the latest audit entries (8) fill the sections as they arrive.
    const sections = [
        { id: 'health', key: 'dashboard.health' },
        { id: 'invitations', key: 'dashboard.invitations' },
        { id: 'audit', key: 'dashboard.audit' },
    ];
</script>

<AppHead title={t('dashboard.title')} />

<div class="tr-page">
    <header class="tr-page-header">
        <h1 class="tr-page-title">{t('dashboard.title')}</h1>
    </header>

    {#each sections as section (section.id)}
        <section class="tr-panel" aria-labelledby="h-{section.id}">
            <h2 id="h-{section.id}" class="tr-panel-title">
                {t(`${section.key}.title`)}
            </h2>
            <EmptyState
                title={t(`${section.key}.empty.title`)}
                body={t(`${section.key}.empty.body`)}
            />
        </section>
    {/each}
</div>
