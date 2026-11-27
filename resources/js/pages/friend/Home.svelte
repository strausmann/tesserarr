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

    // A friend's home (Friend-Home.dc.html): the shares show their empty
    // state until task 21a; the own tokens by name, with the way to the
    // token page.
    type Token = { id: number; name: string; expires_at: string | null };

    let { tokens = [], tokens_total = 0 }: { tokens?: Token[]; tokens_total?: number } = $props();
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
        {#if tokens.length === 0}
            <EmptyState
                title={t('friend.tokens.empty.title')}
                body={t('friend.tokens.empty.body')}
            />
        {:else}
            <ul class="flex flex-col gap-1" aria-label={t('friend.tokens.title')}>
                {#each tokens as token (token.id)}
                    <li>{token.name}</li>
                {/each}
            </ul>
            {#if tokens_total > tokens.length}
                <p class="tr-sub">{t('friend.tokens.more', { count: tokens_total - tokens.length })}</p>
            {/if}
        {/if}
        <div>
            <Link href="/tokens" class="tr-button">{t('friend.tokens.manage')}</Link>
        </div>
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
