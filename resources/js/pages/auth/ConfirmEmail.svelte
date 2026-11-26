<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    export const layout = {
        title: 'account.confirm.title',
        description: 'account.confirm.description',
    };
</script>

<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { t } from '@/lib/i18n';

    // The page behind the link of the confirmation mail (task 8a). Opening
    // the link only shows this page; the button uses the token, so a mail
    // scanner that fetches every link leaves it alone.
    let { token }: { token: string } = $props();

    const errors = $derived(((page.props as { errors?: Record<string, string> }).errors ?? {}) as Record<string, string>);
    let sending = $state(false);

    function confirm(): void {
        sending = true;
        router.post(`/email/confirm/${encodeURIComponent(token)}`, {}, { onFinish: () => (sending = false) });
    }
</script>

<AppHead title={t('account.confirm.title')} />

<div class="flex flex-col gap-4">
    {#if errors.token}
        <p class="tr-error" role="alert">{errors.token}</p>
    {/if}
    <button type="button" class="tr-button tr-button--primary" disabled={sending} onclick={confirm}>
        {t('account.confirm.button')}
    </button>
</div>
