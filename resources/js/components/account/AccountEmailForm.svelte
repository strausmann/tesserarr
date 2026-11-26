<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import Field from '@/components/ui/Field.svelte';
    import { locale, t } from '@/lib/i18n';

    /**
     * The account address (task 8a) with its state: the confirmed address,
     * the one waiting for its link (and until when it is held), and the field
     * to enter one. The address counts once its owner opened the link sent
     * to it. While the operator has not allowed addresses, or no mail can be
     * sent, the field is locked and says why.
     *
     * Used on "My account" (action /account/email) and on the user detail page
     * of the operator (/users/{id}/email), which can also free a reservation.
     */
    export type EmailState = {
        confirmed: string | null;
        pending: string | null;
        pending_until: string | null;
        entry: { allowed: boolean; reason: 'switched_off' | 'no_transport' | null };
    };

    let {
        email,
        action,
        label,
        submit,
        resend = null,
        release = null,
        suggestion = null,
        locked = null,
    }: {
        email: EmailState;
        /** Where the address is sent (PUT). */
        action: string;
        label: string;
        submit: string;
        /** POST that sends the link again, for the own account. */
        resend?: string | null;
        /** DELETE that frees the reservation, for the operator. */
        release?: string | null;
        /** The provider's address, offered while there is none. */
        suggestion?: string | null;
        /** Why the field stays locked although entry is allowed. */
        locked?: string | null;
    } = $props();

    const errors = $derived(((page.props as { errors?: Record<string, string> }).errors ?? {}) as Record<string, string>);

    let value = $state('');
    let sending = $state(false);
    const disabled = $derived(!email.entry.allowed || locked !== null);

    const until = $derived(
        email.pending_until === null
            ? ''
            : new Date(email.pending_until).toLocaleString(locale(), { dateStyle: 'medium', timeStyle: 'short' }),
    );

    function send(address: string): void {
        sending = true;
        router.put(action, { email: address }, {
            preserveScroll: true,
            onSuccess: () => (value = ''),
            onFinish: () => (sending = false),
        });
    }
</script>

<div class="tr-email">
    <dl class="tr-email-state">
        <div>
            <dt class="tr-sub">{t('account.email.confirmed')}</dt>
            <dd class="tr-email-address">{email.confirmed ?? t('account.email.none')}</dd>
        </div>
        {#if email.pending}
            <div>
                <dt class="tr-sub">{t('account.email.pending')}</dt>
                <dd class="tr-email-address">{email.pending}</dd>
                <dd class="tr-sub">{t('account.email.pending_hint', { until })}</dd>
            </div>
        {/if}
    </dl>

    {#if !email.entry.allowed && email.entry.reason}
        <p class="tr-settings-locked" id="email-locked">{t(`account.email.disabled.${email.entry.reason}`)}</p>
    {:else if locked}
        <p class="tr-settings-locked" id="email-locked">{locked}</p>
    {/if}

    <form
        class="tr-email-form"
        onsubmit={(event) => {
            event.preventDefault();
            send(value);
        }}
    >
        <Field
            purpose="email"
            {label}
            bind:value
            name="email"
            required
            {disabled}
            error={errors.email ?? null}
        />
        <div class="tr-email-actions">
            <button type="submit" class="tr-button tr-button--primary" disabled={disabled || sending || value.trim() === ''}>
                {submit}
            </button>
            {#if suggestion && !disabled}
                <button type="button" class="tr-button" disabled={sending} onclick={() => send(suggestion)}>
                    {t('account.email.use_suggestion', { address: suggestion })}
                </button>
            {/if}
            {#if email.pending && resend && email.entry.allowed}
                <button
                    type="button"
                    class="tr-button"
                    disabled={sending}
                    onclick={() => router.post(resend, {}, { preserveScroll: true })}
                >
                    {t('account.email.resend')}
                </button>
            {/if}
            {#if email.pending && release}
                <button
                    type="button"
                    class="tr-button tr-button--danger"
                    onclick={() => router.delete(release, { preserveScroll: true })}
                >
                    {t('account.email.release')}
                </button>
            {/if}
        </div>
    </form>
</div>
