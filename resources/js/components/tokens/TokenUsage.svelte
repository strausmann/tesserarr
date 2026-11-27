<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import Check from '@lucide/svelte/icons/check';
    import Copy from '@lucide/svelte/icons/copy';
    import { t } from '@/lib/i18n';
    import type { TokenRow } from '@/lib/api-tokens';
    import { authorizationHeader } from '@/lib/api-tokens';

    // The panel beside the form (Tokens.dc.html): right after issuing, the
    // token once with its copy button; always, how a script uses a token.
    // The token lives in this component's props only, held by the page in
    // memory; leaving the page drops it.
    let {
        issued = null,
        baseUrl,
        format,
        copy = (text: string) => navigator.clipboard.writeText(text),
    }: {
        issued?: { token: TokenRow; plainText: string } | null;
        baseUrl: string;
        format: (iso: string | null) => string;
        copy?: (text: string) => Promise<void>;
    } = $props();

    // What was copied last, for the check mark; no animation (it is a
    // state change the person asked for, not an event to watch).
    let copied = $state<string | null>(null);

    async function copyIt(key: string, text: string): Promise<void> {
        try {
            await copy(text);
            copied = key;
        } catch {
            copied = null;
        }
    }

    const header = $derived(authorizationHeader(issued ? issued.plainText : '<token>'));
    const example = $derived(`curl -H "Authorization: Bearer …" ${baseUrl}me`);
</script>

<section class="tr-panel tr-token-usage" aria-labelledby={issued ? 'h-issued' : 'h-usage'}>
    {#if issued}
        <div>
            <h2 id="h-issued" class="tr-panel-title">{t('tokens.issued', { name: issued.token.name })}</h2>
            <p class="text-ink-2">{t('tokens.issued_lead')}</p>
        </div>
        <div class="tr-token-secret">
            <code class="tr-code" aria-label={t('tokens.secret')}>{issued.plainText}</code>
            <button type="button" class="tr-button" onclick={() => copyIt('token', issued.plainText)}>
                {copied === 'token' ? t('tokens.copied') : t('tokens.copy')}
            </button>
        </div>
        <dl class="tr-token-facts">
            <dt>{t('tokens.permissions')}</dt>
            <dd>{issued.token.permissions.map((p) => t(`perm.${p}`)).join(', ')}</dd>
            <dt>{t('tokens.valid_until')}</dt>
            <dd>{issued.token.expires_at ? format(issued.token.expires_at) : t('tokens.never')}</dd>
        </dl>
    {/if}

    <div>
        <svelte:element this={issued ? 'h3' : 'h2'} id="h-usage" class="tr-panel-title">{t('tokens.how_to')}</svelte:element>
        <ol class="tr-token-steps">
            {#each [
                { key: 'base', label: t('tokens.step.base'), text: baseUrl, copyLabel: t('tokens.copy_base') },
                { key: 'header', label: t('tokens.step.header'), text: header, copyLabel: t('tokens.copy_header') },
                { key: 'try', label: t('tokens.step.try'), text: example, copyLabel: t('tokens.copy_example') },
            ] as step (step.key)}
                <li>
                    <span id="tk-step-{step.key}">{step.label}</span>
                    <div class="tr-token-secret">
                        <code class="tr-code" aria-labelledby="tk-step-{step.key}">{step.key === 'header' && issued ? authorizationHeader('<token>') : step.text}</code>
                        <button
                            type="button"
                            class="tr-icon-button"
                            aria-label={step.copyLabel}
                            title={step.copyLabel}
                            onclick={() => copyIt(step.key, step.text)}
                        >
                            {#if copied === step.key}
                                <Check size={18} aria-hidden="true" />
                            {:else}
                                <Copy size={18} aria-hidden="true" />
                            {/if}
                        </button>
                    </div>
                </li>
            {/each}
        </ol>
    </div>
</section>
