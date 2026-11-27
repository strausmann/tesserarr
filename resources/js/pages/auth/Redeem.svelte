<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    export const layout = {
        title: 'invitations.redeem.title',
    };
</script>

<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Field from '@/components/ui/Field.svelte';
    import { locale, t } from '@/lib/i18n';
    import { tokenFromHash, tokenFromInput } from '@/lib/invitations';

    // The page behind the link of an invitation (Invite-Phone.dc.html). The
    // token is in the fragment of the link (/invite#<token>), which the
    // browser never sends: it is read here, taken out of the address bar and
    // posted in the body to see who invites. Nothing is used up by that.
    //
    // Two steps in this version: the welcome with who invites and until when,
    // and the choice of the sign-in method. The methods arrive with their
    // tasks (password and passkey in 11, OIDC, Plex and the header in 13 to
    // 15); each redeems the invitation in its own way.
    type Invitation = {
        kind: 'access' | 'recovery';
        inviter: string | null;
        expires_at: string;
        account: string | null;
    };
    type Method = { key: string; label: string; hint: string };

    let { invitation = null, methods = [] }: { invitation?: Invitation | null; methods?: Method[] } = $props();

    const shared = $derived(page.props as { name?: string; errors?: Record<string, string> });
    const errors = $derived(shared.errors ?? {});

    let token = $state('');
    let pasted = $state('');
    let looking = $state(false);
    let step = $state(0);
    let method = $state('');

    const steps = 2;

    onMount(() => {
        const raw = window.location.hash;
        // Out of the address bar, the history and a bookmark.
        if (raw !== '') {
            window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search);
        }
        token = tokenFromHash(raw);
        if (token !== '' && invitation === null) {
            look(token);
        }
    });

    function look(value: string): void {
        looking = true;
        router.post('/invite', { token: value }, { preserveScroll: true, onFinish: () => (looking = false) });
    }

    function lookPasted(event: SubmitEvent): void {
        event.preventDefault();
        const value = tokenFromInput(pasted);
        token = value;
        look(value === '' ? pasted.trim() : value);
    }

    const until = $derived(
        invitation
            ? new Date(invitation.expires_at).toLocaleString(locale(), { dateStyle: 'long', timeStyle: 'short' })
            : '',
    );
</script>

<AppHead title={t('invitations.redeem.title')} />

<div class="tr-redeem flex flex-col gap-6">
    {#if invitation}
        <div
            class="tr-steps"
            role="progressbar"
            aria-label={t('invitations.redeem.progress')}
            aria-valuemin="1"
            aria-valuemax={steps}
            aria-valuenow={step + 1}
            aria-valuetext={t('invitations.redeem.step', { step: String(step + 1), total: String(steps) })}
        >
            {#each Array.from({ length: steps }) as _, index (index)}
                <span class:tr-steps-done={index <= step}></span>
            {/each}
        </div>

        {#if step === 0}
            <section aria-labelledby="h-welcome" class="flex flex-col gap-4">
                <h2 id="h-welcome" class="tr-redeem-title">
                    {invitation.kind === 'recovery'
                        ? t('invitations.redeem.recovery_title')
                        : t('invitations.redeem.welcome', { name: shared.name ?? 'Tesserarr' })}
                </h2>
                <p class="tr-sub">
                    {#if invitation.kind === 'recovery'}
                        {t('invitations.redeem.recovery_lead', { account: invitation.account ?? '' })}
                    {:else if invitation.inviter}
                        {t('invitations.redeem.lead_by', { name: invitation.inviter })}
                    {:else}
                        {t('invitations.redeem.lead')}
                    {/if}
                </p>
                <dl class="tr-facts tr-redeem-card">
                    {#if invitation.inviter}
                        <dt>{t('invitations.redeem.invited_by')}</dt>
                        <dd class="font-bold">{invitation.inviter}</dd>
                    {/if}
                    {#if invitation.account}
                        <dt>{t('invitations.redeem.account')}</dt>
                        <dd class="font-bold">{invitation.account}</dd>
                    {/if}
                    <dt>{t('invitations.col.until')}</dt>
                    <dd class="font-bold">{until}</dd>
                </dl>
                <p class="tr-sub">{t('invitations.redeem.once')}</p>
                <button type="button" class="tr-button tr-button--primary tr-redeem-next" onclick={() => (step = 1)}>
                    {t('invitations.redeem.start')}
                </button>
            </section>
        {:else}
            <section aria-labelledby="h-method" class="flex flex-col gap-4">
                <h2 id="h-method" class="tr-redeem-title">{t('invitations.redeem.method_title')}</h2>
                {#if methods.length === 0}
                    <p role="status">
                        {invitation.inviter
                            ? t('invitations.redeem.no_method_by', { name: invitation.inviter })
                            : t('invitations.redeem.no_method')}
                    </p>
                {:else}
                    <p class="tr-sub">{t('invitations.redeem.method_lead')}</p>
                    <div role="radiogroup" aria-labelledby="h-method" class="flex flex-col gap-2">
                        {#each methods as option (option.key)}
                            <button
                                type="button"
                                role="radio"
                                aria-checked={method === option.key}
                                class="tr-choice"
                                onclick={() => (method = option.key)}
                            >
                                <span class="font-bold">{option.label}</span>
                                <span class="tr-sub">{option.hint}</span>
                            </button>
                        {/each}
                    </div>
                {/if}
                <button type="button" class="tr-button self-start" onclick={() => (step = 0)}>
                    {t('invitations.redeem.back')}
                </button>
            </section>
        {/if}
    {:else}
        {#if errors.token}
            <p class="tr-error" role="alert">{errors.token}</p>
        {:else if token === '' && !looking}
            <p>{t('invitations.redeem.paste_lead')}</p>
        {/if}
        {#if looking && !errors.token}
            <p role="status">{t('invitations.redeem.looking')}</p>
        {:else}
            <!-- novalidate: a bare token is no URL, and tokenFromInput takes both. -->
            <form class="flex flex-col gap-4" novalidate onsubmit={lookPasted}>
                <Field purpose="url" label={t('invitations.redeem.paste')} bind:value={pasted} required />
                <button type="submit" class="tr-button tr-button--primary tr-redeem-next" disabled={pasted.trim() === ''}>
                    {t('invitations.redeem.open')}
                </button>
            </form>
        {/if}
    {/if}
</div>
