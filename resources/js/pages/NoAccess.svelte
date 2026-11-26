<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    export const layout = { title: 'noaccess.title' };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import EmptyState from '@/components/EmptyState.svelte';
    import { t } from '@/lib/i18n';

    // The start of an account that may open nothing yet: no role, or an own
    // role without the catalogue and without administration. No artboard of
    // its own; the empty state of the canvas, with the way to the account.
</script>

<AppHead title={t('noaccess.title')} />

<div class="tr-page">
    <header class="tr-page-header">
        <h1 class="tr-page-title">{t('noaccess.title')}</h1>
    </header>

    <section class="tr-panel" aria-labelledby="h-noaccess">
        <h2 id="h-noaccess" class="tr-panel-title">{t('noaccess.empty.title')}</h2>
        <EmptyState title={t('noaccess.empty.title')} body={t('noaccess.empty.body')} />
        <div>
            <Link href="/settings/profile" class="tr-button">{t('friend.account.manage')}</Link>
        </div>
    </section>
</div>
