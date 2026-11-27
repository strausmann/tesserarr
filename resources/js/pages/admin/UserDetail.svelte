<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    export const layout = { title: 'users.title' };
</script>

<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import AccountEmailForm from '@/components/account/AccountEmailForm.svelte';
    import type { EmailState } from '@/components/account/AccountEmailForm.svelte';
    import ActivityList from '@/components/account/ActivityList.svelte';
    import type { ActivityEntry } from '@/components/account/ActivityList.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import EmptyState from '@/components/EmptyState.svelte';
    import RecoveryInvitation from '@/components/invitations/RecoveryInvitation.svelte';
    import { t } from '@/lib/i18n';

    // One account for the operator (User-Detail.dc.html). Task 8a builds the
    // contact section (the account address, confirmed by its owner through a
    // link), the sign-in methods with the provider addresses and the latest
    // log entries; task 10 the recovery invitation. Second factors (12) and
    // grants (21a) join with their tasks. Role and lock stay on the list.
    type Identity = { id: number; method: string; provider_key: string; provider_email: string | null };

    let {
        account,
        email,
        identities,
        entries,
    }: {
        account: {
            id: number;
            username: string;
            display_name: string;
            role: string | null;
            locked_at: string | null;
            self: boolean;
        };
        email: EmailState;
        identities: Identity[];
        entries: ActivityEntry[];
    } = $props();

    const shown = $derived(account.display_name.trim() !== '' ? account.display_name : account.username);
    const granted = $derived((page.props as { permissions?: string[] }).permissions ?? []);
    const canManage = $derived(granted.includes('users.manage'));
    // A recovery invitation also needs invitations.create; the server checks the rest.
    const canRecover = $derived(canManage && granted.includes('invitations.create') && !account.self);
    const role = $derived(
        account.role === 'operator' || account.role === 'user' ? t(`roles.name.${account.role}`) : (account.role ?? t('users.no_role')),
    );
</script>

<AppHead title={shown} />

<div class="tr-page">
    <header class="tr-page-header">
        <div>
            <h1 class="tr-page-title">{shown}</h1>
            <p class="tr-lead">
                {account.username} · {role}{#if account.locked_at}
                    · <span class="tr-tag tr-tag--bad">{t('users.locked')}</span>{/if}
            </p>
        </div>
        <Link href="/users" class="tr-button">{t('users.back')}</Link>
    </header>

    <div class="tr-settings-grid tr-settings-grid--two">
        <section aria-labelledby="h-contact" class="tr-panel">
            <h2 id="h-contact" class="tr-panel-title">{t('users.detail.contact')}</h2>
            <p class="tr-sub">{t('users.detail.contact_lead')}</p>
            <AccountEmailForm
                {email}
                action="/users/{account.id}/email"
                release={canManage ? `/users/${account.id}/email/pending` : null}
                label={t('users.detail.email_of', { name: shown })}
                submit={t('users.detail.email_save')}
                locked={canManage ? null : t('users.detail.read_only')}
            />
        </section>

        <section aria-labelledby="h-methods" class="tr-panel">
            <h2 id="h-methods" class="tr-panel-title">{t('users.detail.methods')}</h2>
            {#if identities.length === 0}
                <p class="tr-sub">{t('users.local_only')}</p>
            {:else}
                <ul class="tr-activity" aria-labelledby="h-methods">
                    {#each identities as identity (identity.id)}
                        <li class="tr-activity-row">
                            <span class="tr-tag">{identity.method} · {identity.provider_key}</span>
                            <span class="tr-sub">
                                {t('users.provider_email')}:
                                <span class="tr-email-address">{identity.provider_email ?? t('users.none')}</span>
                            </span>
                        </li>
                    {/each}
                </ul>
            {/if}
            {#if canRecover}
                <RecoveryInvitation userId={account.id} name={shown} />
            {/if}
        </section>
    </div>

    <section aria-labelledby="h-entries" class="tr-panel">
        <h2 id="h-entries" class="tr-panel-title">{t('users.detail.entries')}</h2>
        {#if entries.length === 0}
            <EmptyState title={t('users.detail.entries_empty')} body={t('users.detail.entries_empty_body')} />
        {:else}
            <ActivityList {entries} label={t('users.detail.entries')} />
        {/if}
    </section>
</div>
