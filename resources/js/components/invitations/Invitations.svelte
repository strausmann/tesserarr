<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import ChevronRight from '@lucide/svelte/icons/chevron-right';
    import Dialog from '@/components/ui/Dialog.svelte';
    import EmptyState from '@/components/EmptyState.svelte';
    import Field from '@/components/ui/Field.svelte';
    import Segmented from '@/components/ui/Segmented.svelte';
    import { locale, t } from '@/lib/i18n';
    import type { InvitationRow } from '@/lib/invitations';
    import { daysLeft, issueInvitation, issueRequest, revokeInvitation, validityChoices } from '@/lib/invitations';

    /**
     * The invitation part of the user list (Users.dc.html, task 10): the open
     * invitations, the dialog that makes one, the dialog that shows its link
     * once, and the detail with its danger zone. Sending the link by mail
     * arrives with task 10a; the role and the groups of the canvas with later
     * versions (a redeemed invitation makes a user, the operator changes the
     * role afterwards).
     */
    let {
        invitations = $bindable([]),
        canCreate,
        canRevoke,
        defaultDays = 7,
        copy = (text: string) => navigator.clipboard.writeText(text),
        now = () => new Date(),
    }: {
        invitations?: InvitationRow[];
        canCreate: boolean;
        canRevoke: boolean;
        /** The general setting "Validity of new invitations": the preselected choice. */
        defaultDays?: number;
        copy?: (text: string) => Promise<void>;
        now?: () => Date;
    } = $props();

    let creating = $state(false);
    let note = $state('');
    const choices = $derived(validityChoices(defaultDays));
    let validity = $state('7');
    let sending = $state(false);
    let createErrors = $state<Record<string, string>>({});

    let issued = $state<{ invitation: InvitationRow; url: string } | null>(null);
    let copied = $state(false);

    let selected = $state<InvitationRow | null>(null);
    let asking = $state(false);
    let revokeError = $state('');

    const dateFormat = $derived(new Intl.DateTimeFormat(locale(), { dateStyle: 'medium', timeStyle: 'short' }));

    function when(iso: string | null): string {
        return iso ? dateFormat.format(new Date(iso)) : '';
    }

    function left(row: InvitationRow): string {
        const days = daysLeft(row.expires_at, now());

        return days === 0 ? t('invitations.expired') : t(days === 1 ? 'invitations.left_one' : 'invitations.left', { days: String(days) });
    }

    function label(row: InvitationRow): string {
        if (row.kind === 'recovery') {
            return t('invitations.recovery_for', { name: row.for_user ?? '' });
        }

        return row.note ?? t('invitations.no_note');
    }

    function creator(row: InvitationRow): string {
        const via = t(`invitations.via.${row.via}`);

        return row.created_by ? `${row.created_by} · ${via}` : via;
    }

    function openCreate(): void {
        note = '';
        validity = String(defaultDays >= 1 && defaultDays <= 90 ? defaultDays : 7);
        createErrors = {};
        creating = true;
    }

    async function create(): Promise<void> {
        sending = true;
        const result = await issueInvitation(issueRequest(validity, note));
        sending = false;

        if (result.ok) {
            creating = false;
            issued = { invitation: result.invitation, url: result.url };
            copied = false;
            invitations = [result.invitation, ...invitations];

            return;
        }
        createErrors = result.reason === 'validation' ? result.errors : { form: t(`invitations.error.${result.reason}`) };
    }

    async function copyLink(): Promise<void> {
        if (!issued) {
            return;
        }
        try {
            await copy(issued.url);
            copied = true;
        } catch {
            copied = false;
        }
    }

    function closeIssued(): void {
        // The link is gone from the page with the dialog.
        issued = null;
    }

    function openDetail(row: InvitationRow): void {
        selected = row;
        asking = false;
        revokeError = '';
    }

    async function revoke(): Promise<void> {
        if (!selected) {
            return;
        }
        const id = selected.id;
        const result = await revokeInvitation(id);
        if (result === 'revoked' || result === 'gone') {
            invitations = invitations.filter((row) => row.id !== id);
            selected = null;

            return;
        }
        revokeError = t(`invitations.error.${result}`);
    }
</script>

<section id="invitations" aria-labelledby="h-invitations" class="flex flex-col gap-3">
    <div class="tr-section-head">
        <div>
            <h2 id="h-invitations" class="tr-panel-title">{t('invitations.open', { count: String(invitations.length) })}</h2>
            <p class="tr-sub">{t('invitations.lead')}</p>
        </div>
        {#if canCreate && invitations.length > 0}
            <button type="button" class="tr-button tr-button--primary" aria-haspopup="dialog" onclick={openCreate}>
                {t('invitations.invite')}
            </button>
        {/if}
    </div>

    {#if invitations.length === 0}
        <EmptyState title={t('invitations.empty.title')} body={t('invitations.empty.body')}>
            {#snippet action()}
                {#if canCreate}
                    <button type="button" class="tr-button" aria-haspopup="dialog" onclick={openCreate}>
                        {t('invitations.invite')}
                    </button>
                {/if}
            {/snippet}
        </EmptyState>
    {:else}
        <div class="tr-table-wrap">
            <table class="tr-table" aria-label={t('invitations.open_label')}>
                <thead>
                    <tr>
                        <th scope="col">{t('invitations.col.note')}</th>
                        <th scope="col">{t('invitations.col.until')}</th>
                        <th scope="col">{t('invitations.col.by')}</th>
                        <th scope="col"><span class="sr-only">{t('invitations.col.open')}</span></th>
                    </tr>
                </thead>
                <tbody>
                    {#each invitations as row (row.id)}
                        <tr>
                            <th scope="row" class="font-bold">{label(row)}</th>
                            <td>
                                <span class="block">{left(row)}</span>
                                <span class="tr-sub">{when(row.expires_at)}</span>
                            </td>
                            <td>{creator(row)}</td>
                            <td>
                                <button
                                    type="button"
                                    class="tr-icon-button"
                                    aria-haspopup="dialog"
                                    aria-label={t('invitations.open_one', { name: label(row) })}
                                    title={t('invitations.open_one', { name: label(row) })}
                                    onclick={() => openDetail(row)}
                                >
                                    <ChevronRight size={18} aria-hidden="true" />
                                </button>
                            </td>
                        </tr>
                    {/each}
                </tbody>
            </table>
        </div>
    {/if}
</section>

<Dialog bind:open={creating} title={t('invitations.create.title')} variant="drawer">
    <form
        id="invitation-form"
        class="flex flex-col gap-4"
        onsubmit={(event) => {
            event.preventDefault();
            void create();
        }}
    >
        <Field
            purpose="name"
            label={t('invitations.create.note')}
            bind:value={note}
            hint={t('invitations.create.note_hint')}
            placeholder={t('invitations.create.note_placeholder')}
            error={createErrors.note ?? null}
        />
        <Segmented
            id="invitation-validity"
            label={t('invitations.create.validity')}
            options={choices.map((days) => ({ value: days, label: t(days === '1' ? 'invitations.days_one' : 'invitations.days', { days }) }))}
            bind:value={validity}
            hint={t('invitations.create.validity_hint')}
        />
        {#if createErrors.days || createErrors.form}
            <p class="tr-error" role="alert">{createErrors.days ?? createErrors.form}</p>
        {/if}
    </form>
    {#snippet footer()}
        <button type="button" class="tr-button" onclick={() => (creating = false)}>{t('invitations.cancel')}</button>
        <button type="submit" form="invitation-form" class="tr-button tr-button--primary" disabled={sending}>
            {t('invitations.create.submit')}
        </button>
    {/snippet}
</Dialog>

<Dialog
    open={issued !== null}
    title={issued ? t('invitations.done.title', { name: label(issued.invitation) }) : ''}
    variant="drawer"
    onclose={closeIssued}
>
    {#if issued}
        <p class="tr-sub">{t('invitations.done.meta', { date: when(issued.invitation.expires_at) })}</p>
        <div class="tr-invite-link">
            <code class="tr-code" aria-label={t('invitations.link')}>{issued.url}</code>
            <button type="button" class="tr-button" onclick={copyLink}>
                {copied ? t('invitations.copied') : t('invitations.copy')}
            </button>
        </div>
        <p role="status">{t('invitations.done.once')}</p>
    {/if}
    {#snippet footer()}
        <button type="button" class="tr-button tr-button--primary" onclick={closeIssued}>{t('invitations.done.close')}</button>
    {/snippet}
</Dialog>

<Dialog
    open={selected !== null}
    title={selected ? t('invitations.detail.title', { name: label(selected) }) : ''}
    variant="drawer"
    onclose={() => (selected = null)}
>
    {#if selected}
        <dl class="tr-facts">
            <dt>{t('invitations.detail.kind')}</dt>
            <dd>{t(`invitations.kind.${selected.kind}`)}</dd>
            <dt>{t('invitations.detail.created')}</dt>
            <dd>{when(selected.created_at)}<br /><span class="tr-sub">{creator(selected)}</span></dd>
            <dt>{t('invitations.col.until')}</dt>
            <dd>{when(selected.expires_at)}<br /><span class="tr-sub">{left(selected)}</span></dd>
        </dl>
        {#if canRevoke}
            <section aria-labelledby="h-danger" class="tr-danger-zone flex flex-col gap-3">
                <h3 id="h-danger" class="font-bold">{t('invitations.detail.danger')}</h3>
                <p>{t('invitations.detail.danger_body')}</p>
                {#if asking}
                    <div role="alert" class="flex flex-col gap-3">
                        <p>{t('invitations.detail.ask', { name: label(selected) })}</p>
                        {#if revokeError}
                            <p class="tr-error">{revokeError}</p>
                        {/if}
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="tr-button" onclick={() => (asking = false)}>{t('invitations.cancel')}</button>
                            <button type="button" class="tr-button tr-button--danger" onclick={revoke}>{t('invitations.detail.revoke_now')}</button>
                        </div>
                    </div>
                {:else}
                    <button type="button" class="tr-button tr-button--danger self-start" onclick={() => (asking = true)}>
                        {t('invitations.detail.revoke')}
                    </button>
                {/if}
            </section>
        {/if}
    {/if}
</Dialog>
