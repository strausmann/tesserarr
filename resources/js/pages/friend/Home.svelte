<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    export const layout = { title: 'nav.catalog' };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import EmptyState from '@/components/EmptyState.svelte';
    import { t } from '@/lib/i18n';

    // A friend's home (Friend-Home.dc.html) before anything is shared: the
    // shares (task 21a) and the tokens (task 9) show their empty state.
</script>

<AppHead title={t('nav.catalog')} />

<div class="tr-page">
    <header class="tr-page-header">
        <h1 class="tr-page-title">{t('friend.title')}</h1>
    </header>

    <section class="tr-panel" aria-labelledby="h-shares">
        <h2 id="h-shares" class="tr-panel-title">{t('friend.shares.title')}</h2>
        <EmptyState
            title={t('friend.shares.empty.title')}
            body={t('friend.shares.empty.body')}
        />
    </section>

    <section class="tr-panel" aria-labelledby="h-tokens">
        <h2 id="h-tokens" class="tr-panel-title">{t('friend.tokens.title')}</h2>
        <EmptyState
            title={t('friend.tokens.empty.title')}
            body={t('friend.tokens.empty.body')}
        />
    </section>

    <section class="tr-panel" aria-labelledby="h-account">
        <h2 id="h-account" class="tr-panel-title">{t('friend.account.title')}</h2>
        <p class="text-ink-2">{t('friend.account.text')}</p>
        <div>
            <Link href="/account" class="tr-button">
                {t('friend.account.manage')}
            </Link>
        </div>
    </section>
</div>
