<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    export const layout = { title: 'users.title' };
</script>

<script lang="ts">
    import { Link, page, router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import EmptyState from '@/components/EmptyState.svelte';
    import { t } from '@/lib/i18n';

    // The accounts (Users.dc.html): who has which role, how they sign in,
    // the contact address of the account and the addresses the sign-in
    // providers reported, each labelled as what it is. Invitations join the
    // page with task 10, second factors with task 12.
    type Identity = {
        method: string;
        provider_key: string;
        provider_email: string | null;
    };
    type Account = {
        id: number;
        username: string;
        display_name: string;
        contact_email: string | null;
        locked_at: string | null;
        role: string | null;
        identities: Identity[];
        self: boolean;
    };

    let { users, roles }: { users: Account[]; roles: string[] } = $props();

    const shared = $derived(
        page.props as { permissions?: string[]; errors?: Record<string, string> },
    );
    const canManage = $derived((shared.permissions ?? []).includes('users.manage'));
    const errors = $derived(shared.errors ?? {});

    // The role chosen in each row's select, until it is saved.
    let chosen = $state<Record<number, string>>({});

    function shownName(account: Account): string {
        return account.display_name.trim() !== '' ? account.display_name : account.username;
    }

    function roleLabel(role: string | null): string {
        if (role === 'operator' || role === 'user') {
            return t(`roles.name.${role}`);
        }

        return role ?? t('users.no_role');
    }

    function saveRole(account: Account): void {
        const role = chosen[account.id] ?? account.role;
        if (role) {
            router.put(`/users/${account.id}/role`, { role }, { preserveScroll: true });
        }
    }

    function setLock(account: Account, locked: boolean): void {
        const url = `/users/${account.id}/lock`;
        if (locked) {
            router.post(url, {}, { preserveScroll: true });
        } else {
            router.delete(url, { preserveScroll: true });
        }
    }
</script>

<AppHead title={t('users.title')} />

<div class="tr-page">
    <header class="tr-page-header">
        <div>
            <h1 class="tr-page-title">{t('users.title')}</h1>
            <p class="tr-lead">{t('users.lead')}</p>
        </div>
    </header>

    <section aria-labelledby="h-users" class="flex flex-col gap-3">
        <h2 id="h-users" class="tr-panel-title">{t('users.all')}</h2>

        {#if errors.role || errors.lock}
            <p class="tr-error" role="alert">{errors.role ?? errors.lock}</p>
        {/if}

        {#if users.length === 0}
            <EmptyState title={t('users.empty.title')} body={t('users.empty.body')} />
        {:else}
            <div class="tr-table-wrap">
                <table class="tr-table" aria-label={t('users.all')}>
                    <thead>
                        <tr>
                            <th scope="col">{t('users.col.user')}</th>
                            <th scope="col">{t('users.col.role')}</th>
                            <th scope="col">{t('users.col.sign_in')}</th>
                            <th scope="col">{t('users.contact_email')}</th>
                            {#if canManage}
                                <th scope="col">{t('users.col.actions')}</th>
                            {/if}
                        </tr>
                    </thead>
                    <tbody>
                        {#each users as account (account.id)}
                            <tr>
                                <th scope="row">
                                    <Link href="/users/{account.id}" class="tr-row-link font-bold">{shownName(account)}</Link>
                                    <span class="tr-sub">
                                        {account.username}{#if account.self}
                                            · {t('users.you')}{/if}
                                    </span>
                                    {#if account.locked_at}
                                        <span class="tr-tag tr-tag--bad">{t('users.locked')}</span>
                                    {/if}
                                </th>
                                <td>
                                    {#if canManage}
                                        <div class="tr-role-cell">
                                        <label class="sr-only" for="role-{account.id}">
                                            {t('users.role_of', { name: shownName(account) })}
                                        </label>
                                        <select
                                            id="role-{account.id}"
                                            class="tr-select"
                                            value={chosen[account.id] ?? account.role ?? ''}
                                            onchange={(e) => (chosen[account.id] = e.currentTarget.value)}
                                        >
                                            {#each roles as role (role)}
                                                <option value={role}>{roleLabel(role)}</option>
                                            {/each}
                                        </select>
                                        <button
                                            type="button"
                                            class="tr-button"
                                            onclick={() => saveRole(account)}
                                        >
                                            {t('users.save_role')}
                                        </button>
                                        </div>
                                    {:else}
                                        {roleLabel(account.role)}
                                    {/if}
                                </td>
                                <td>
                                    {#if account.identities.length === 0}
                                        <span class="tr-sub">{t('users.local_only')}</span>
                                    {:else}
                                        <ul class="flex flex-col gap-1">
                                            {#each account.identities as identity (identity.method + identity.provider_key)}
                                                <li>
                                                    <span class="tr-tag">{identity.method} · {identity.provider_key}</span>
                                                    <span class="tr-sub">
                                                        {t('users.provider_email')}:
                                                        {identity.provider_email ?? t('users.none')}
                                                    </span>
                                                </li>
                                            {/each}
                                        </ul>
                                    {/if}
                                </td>
                                <td>{account.contact_email ?? t('users.none')}</td>
                                {#if canManage}
                                    <td>
                                        {#if !account.self}
                                            {#if account.locked_at}
                                                <button
                                                    type="button"
                                                    class="tr-button"
                                                    aria-label={t('users.unlock', { name: shownName(account) })}
                                                    onclick={() => setLock(account, false)}
                                                >
                                                    {t('users.unlock_short')}
                                                </button>
                                            {:else}
                                                <button
                                                    type="button"
                                                    class="tr-button tr-button--danger"
                                                    aria-label={t('users.lock', { name: shownName(account) })}
                                                    onclick={() => setLock(account, true)}
                                                >
                                                    {t('users.lock_short')}
                                                </button>
                                            {/if}
                                        {/if}
                                    </td>
                                {/if}
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        {/if}
    </section>
</div>
