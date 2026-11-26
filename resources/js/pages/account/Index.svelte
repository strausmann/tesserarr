<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    export const layout = { title: 'account.title' };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AccountEmailForm from '@/components/account/AccountEmailForm.svelte';
    import type { EmailState } from '@/components/account/AccountEmailForm.svelte';
    import ActivityList from '@/components/account/ActivityList.svelte';
    import type { ActivityEntry } from '@/components/account/ActivityList.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import EmptyState from '@/components/EmptyState.svelte';
    import LanguageChoice from '@/components/LanguageChoice.svelte';
    import { t } from '@/lib/i18n';
    import Security from './Security.svelte';

    // My account (Account.dc.html, pre-flight K22): the own account in plain
    // text, nobody else's. Task 8a builds the frame with the account address,
    // the addresses the sign-in providers reported, the language and the own
    // activity; tokens, the PGP key, passkeys, TOTP and the sign-in methods
    // join with their tasks.
    type Identity = { id: number; method: string; provider_key: string; email: string | null };

    let {
        account,
        email,
        identities,
        suggestion,
        security,
        activity,
    }: {
        account: { username: string; display_name: string };
        email: EmailState;
        identities: Identity[];
        suggestion: string | null;
        security: { passkeys: number; two_factor: boolean };
        activity: ActivityEntry[];
    } = $props();

    const shown = $derived(account.display_name.trim() !== '' ? account.display_name : account.username);
</script>

<AppHead title={t('account.title')} />

<div class="tr-page">
    <header class="tr-page-header">
        <div>
            <h1 class="tr-page-title">{t('account.title')}</h1>
            <p class="tr-lead">{t('account.lead', { name: shown, username: account.username })}</p>
        </div>
        <Link href="/settings/profile" class="tr-button">{t('account.edit_profile')}</Link>
    </header>

    <div class="tr-settings-grid tr-settings-grid--two">
        <section aria-labelledby="h-email" class="tr-panel">
            <h2 id="h-email" class="tr-panel-title">{t('account.email.title')}</h2>
            <p class="tr-sub">{t('account.email.lead')}</p>
            <AccountEmailForm
                {email}
                action="/account/email"
                resend="/account/email/resend"
                label={t('account.email.field')}
                submit={t('account.email.save')}
                {suggestion}
                locked={email.confirmed !== null ? t('account.email.disabled.confirmed_exists') : null}
            />
        </section>

        <Security {security} />
    </div>

    <section aria-labelledby="h-identities" class="tr-panel">
        <h2 id="h-identities" class="tr-panel-title">{t('account.identities.title')}</h2>
        {#if identities.length === 0}
            <p class="tr-sub">{t('account.identities.none')}</p>
        {:else}
            <ul class="tr-activity" aria-labelledby="h-identities">
                {#each identities as identity (identity.id)}
                    <li class="tr-activity-row">
                        <span class="tr-tag">{identity.method} · {identity.provider_key}</span>
                        <span class="tr-sub">
                            {t('users.provider_email')}: <span class="tr-email-address">{identity.email ?? t('users.none')}</span>
                        </span>
                    </li>
                {/each}
            </ul>
        {/if}
    </section>

    <section aria-labelledby="h-display" class="tr-panel">
        <h2 id="h-display" class="tr-panel-title">{t('account.display.title')}</h2>
        <LanguageChoice />
    </section>

    <section aria-labelledby="h-activity" class="tr-panel">
        <h2 id="h-activity" class="tr-panel-title">{t('account.activity.title')}</h2>
        {#if activity.length === 0}
            <EmptyState title={t('account.activity.empty.title')} body={t('account.activity.empty.body')} />
        {:else}
            <ActivityList entries={activity} label={t('account.activity.title')} />
            <Link href="/account/activity" class="tr-button self-start">{t('account.activity.all')}</Link>
        {/if}
    </section>
</div>
