<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import EmptyState from '@/components/EmptyState.svelte';
    import Dialog from '@/components/ui/Dialog.svelte';
    import { locale, t } from '@/lib/i18n';
    import type { Preset, TokenRow } from '@/lib/api-tokens';
    import { revokeToken } from '@/lib/api-tokens';
    import TokenForm from './TokenForm.svelte';
    import TokenUsage from './TokenUsage.svelte';

    // The token page (Tokens.dc.html), shared by account/Tokens (the own
    // tokens) and admin/Tokens (every token, with its owner). The token
    // itself is never part of the props: right after issuing it is held
    // here, in memory, and shown once.
    let {
        scope,
        tokens,
        grantable,
        administrative,
        presets,
        can,
        base_url,
        send = undefined,
        copy = undefined,
    }: {
        scope: 'own' | 'all';
        tokens: TokenRow[];
        grantable: string[];
        administrative: string[];
        presets: Preset[];
        can: { issue: boolean; revoke_any: boolean; view_roles: boolean };
        base_url: string;
        send?: typeof fetch;
        copy?: (text: string) => Promise<void>;
    } = $props();

    let issued = $state<{ token: TokenRow; plainText: string } | null>(null);
    let selectedId = $state<number | null>(null);
    let confirming = $state(false);
    let revokeError = $state<string | null>(null);

    // The token shown in detail: the chosen one, else the newest.
    const selected = $derived(tokens.find((tk) => tk.id === selectedId) ?? tokens[0] ?? null);

    const dateFormat = $derived(
        new Intl.DateTimeFormat(locale(), { dateStyle: 'medium', timeStyle: 'short' }),
    );

    function format(iso: string | null): string {
        return iso ? dateFormat.format(new Date(iso)) : '–';
    }

    function ownerName(token: TokenRow): string {
        if (!token.owner) {
            return '';
        }

        return token.owner.display_name.trim() !== '' ? token.owner.display_name : token.owner.username;
    }

    function permissionsOf(token: TokenRow): string {
        return token.permissions.map((p) => t(`perm.${p}`)).join(', ') || '–';
    }

    function onissued(token: TokenRow, plainText: string): void {
        issued = { token, plainText };
        selectedId = token.id;
        router.reload({ only: ['tokens'] });
    }

    async function revoke(): Promise<void> {
        if (!selected) {
            return;
        }
        const gone = selected;
        revokeError = null;
        if (await revokeToken(gone, send)) {
            confirming = false;
            if (issued?.token.id === gone.id) {
                issued = null;
            }
            selectedId = null;
            router.reload({ only: ['tokens'] });
        } else {
            revokeError = t('tokens.revoke_failed');
        }
    }
</script>

<AppHead title={t(scope === 'all' ? 'nav.tokens' : 'nav.my_tokens')} />

<div class="tr-page">
    <header class="tr-page-header">
        <div>
            <h1 class="tr-page-title">{t(scope === 'all' ? 'tokens.title' : 'tokens.title_own')}</h1>
            <p class="tr-lead">{t('tokens.lead')}</p>
        </div>
    </header>

    {#if can.issue}
        <div class="tr-token-grid">
            <TokenForm
                {grantable}
                {administrative}
                {presets}
                canViewRoles={can.view_roles}
                {onissued}
                {send}
            />
            <TokenUsage {issued} baseUrl={base_url} {format} {copy} />
        </div>
    {/if}

    <section aria-labelledby="h-token-list" class="flex flex-col gap-3">
        <h2 id="h-token-list" class="tr-panel-title">{t('tokens.existing')}</h2>

        {#if tokens.length === 0}
            <EmptyState title={t('tokens.empty.title')} body={t('tokens.empty.body')} />
        {:else}
            <div class="tr-table-wrap">
                <table class="tr-table" aria-label={t('tokens.existing')}>
                    <thead>
                        <tr>
                            <th scope="col">{t(scope === 'all' ? 'tokens.col.name_owner' : 'tokens.col.name')}</th>
                            <th scope="col">{t('tokens.permissions')}</th>
                            <th scope="col">{t('tokens.col.last_used')}</th>
                            <th scope="col">{t('tokens.col.expires')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {#each tokens as token (token.id)}
                            <tr aria-current={selected?.id === token.id ? 'true' : undefined}>
                                <th scope="row">
                                    <a
                                        href="#token-detail"
                                        class="tr-row-link font-bold"
                                        onclick={() => (selectedId = token.id)}
                                    >
                                        {token.name}
                                    </a>
                                    {#if scope === 'all'}
                                        <span class="tr-sub">
                                            {ownerName(token)}{#if token.mine}
                                                · {t('users.you')}{/if}
                                        </span>
                                    {/if}
                                </th>
                                <td>{permissionsOf(token)}</td>
                                <td class="tr-nowrap">{token.last_used_at ? format(token.last_used_at) : t('tokens.never_used')}</td>
                                <td class="tr-nowrap">{token.expires_at ? format(token.expires_at) : t('tokens.never')}</td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        {/if}
    </section>

    {#if selected}
        <section id="token-detail" class="tr-panel" aria-labelledby="h-token-detail">
            <div>
                <h2 id="h-token-detail" class="tr-panel-title">{t('tokens.detail', { name: selected.name })}</h2>
                <p class="tr-sub">{t('tokens.detail_note')}</p>
            </div>
            <dl class="tr-token-facts">
                {#if selected.owner}
                    <dt>{t('tokens.owner')}</dt>
                    <dd>{ownerName(selected)}</dd>
                {/if}
                <dt>{t('tokens.permissions')}</dt>
                <dd>{permissionsOf(selected)}</dd>
                <dt>{t('tokens.col.last_used')}</dt>
                <dd>{selected.last_used_at ? format(selected.last_used_at) : t('tokens.never_used')}</dd>
                <dt>{t('tokens.col.expires')}</dt>
                <dd>{selected.expires_at ? format(selected.expires_at) : t('tokens.never')}</dd>
            </dl>

            {#if selected.mine || can.revoke_any}
                <section class="tr-danger-zone" aria-labelledby="h-token-danger">
                    <div>
                        <h3 id="h-token-danger" class="tr-panel-title">{t('tokens.danger')}</h3>
                        <p class="text-ink-2">{t('tokens.danger_text')}</p>
                    </div>
                    <button type="button" class="tr-button tr-button--danger" onclick={() => (confirming = true)}>
                        {t('tokens.revoke')}
                    </button>
                </section>
            {/if}
        </section>

        <Dialog bind:open={confirming} title={t('tokens.revoke_title', { name: selected.name })}>
            <p>{t('tokens.revoke_confirm')}</p>
            {#if revokeError}
                <p class="tr-error" role="alert">{revokeError}</p>
            {/if}
            {#snippet footer()}
                <button type="button" class="tr-button" onclick={() => (confirming = false)}>
                    {t('tokens.keep')}
                </button>
                <button type="button" class="tr-button tr-button--danger" onclick={revoke}>
                    {t('tokens.revoke_now')}
                </button>
            {/snippet}
        </Dialog>
    {/if}
</div>
