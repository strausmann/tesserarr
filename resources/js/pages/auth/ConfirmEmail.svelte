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
    import { onMount } from 'svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { t } from '@/lib/i18n';

    // The page behind the link of the confirmation mail (task 8a). The token
    // is in the fragment of the link (/email/confirm#<token>), which the
    // browser never sends: it is read here and posted in the body when the
    // button is pressed, so a mail scanner that fetches every link leaves it
    // alone.
    const errors = $derived(((page.props as { errors?: Record<string, string> }).errors ?? {}) as Record<string, string>);
    let token = $state('');
    let sending = $state(false);

    onMount(() => {
        const raw = window.location.hash.replace(/^#/, '');
        try {
            token = decodeURIComponent(raw);
        } catch {
            // A cut or altered link: shown as not valid.
            token = '';
        }
        // Out of the address bar, the history and a bookmark.
        if (raw !== '') {
            window.history.replaceState(
                window.history.state,
                '',
                window.location.pathname + window.location.search,
            );
        }
    });

    function confirm(): void {
        sending = true;
        router.post('/email/confirm', { token }, { onFinish: () => (sending = false) });
    }
</script>

<AppHead title={t('account.confirm.title')} />

<div class="flex flex-col gap-4">
    {#if errors.token}
        <p class="tr-error" role="alert">{errors.token}</p>
    {:else if token === ''}
        <p class="tr-error" role="alert">{t('account.email.link_invalid')}</p>
    {/if}
    <button type="button" class="tr-button tr-button--primary" disabled={sending || token === ''} onclick={confirm}>
        {t('account.confirm.button')}
    </button>
</div>
