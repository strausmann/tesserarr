<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import Dialog from '@/components/ui/Dialog.svelte';
    import { t } from '@/lib/i18n';
    import { issueInvitation, issueRequest } from '@/lib/invitations';

    /**
     * The last resort of Spec 9.3 on the user detail: a recovery invitation
     * binds a new sign-in method to this account, which keeps its role,
     * shares and history. Its link is shown once, like any invitation.
     */
    let {
        userId,
        name,
        copy = (text: string) => navigator.clipboard.writeText(text),
    }: { userId: number; name: string; copy?: (text: string) => Promise<void> } = $props();

    let url = $state<string | null>(null);
    let error = $state('');
    let sending = $state(false);
    let copied = $state(false);

    async function issue(): Promise<void> {
        sending = true;
        error = '';
        const result = await issueInvitation(issueRequest('7', '', userId));
        sending = false;
        if (result.ok) {
            url = result.url;
            copied = false;
        } else {
            error = t(`invitations.error.${result.reason === 'validation' ? 'failed' : result.reason}`);
        }
    }

    async function copyLink(): Promise<void> {
        if (url !== null) {
            try {
                await copy(url);
                copied = true;
            } catch {
                copied = false;
            }
        }
    }
</script>

<div class="flex flex-col gap-2">
    <p class="tr-sub">{t('invitations.recovery.lead')}</p>
    {#if error}
        <p class="tr-error" role="alert">{error}</p>
    {/if}
    <button type="button" class="tr-button self-start" aria-haspopup="dialog" disabled={sending} onclick={issue}>
        {t('invitations.recovery.issue')}
    </button>
</div>

<Dialog open={url !== null} title={t('invitations.recovery.title', { name })} variant="drawer" onclose={() => (url = null)}>
    {#if url}
        <div class="tr-invite-link">
            <code class="tr-code" aria-label={t('invitations.link')}>{url}</code>
            <button type="button" class="tr-button" onclick={copyLink}>
                {copied ? t('invitations.copied') : t('invitations.copy')}
            </button>
        </div>
        <p role="status">{t('invitations.recovery.once')}</p>
    {/if}
    {#snippet footer()}
        <button type="button" class="tr-button tr-button--primary" onclick={() => (url = null)}>{t('invitations.done.close')}</button>
    {/snippet}
</Dialog>
