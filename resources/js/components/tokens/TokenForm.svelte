<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import Field from '@/components/ui/Field.svelte';
    import { t } from '@/lib/i18n';
    import type { Expiry, IssueResult, Preset, TokenRow } from '@/lib/api-tokens';
    import {
        COMMON_PERMISSIONS,
        CUSTOM,
        DEFAULT_EXPIRY,
        EXPIRY_CHOICES,
        inCatalogueOrder,
        issueToken,
        presetOf,
    } from '@/lib/api-tokens';

    // "Neues Token" (Tokens.dc.html): name, a preset or an own selection of
    // what the account may grant, the expiry. Administrative permissions are
    // marked and never ticked by a preset (Spec 9.5). The token goes back to
    // the page through `onissued`, never into a prop or a URL.
    let {
        grantable,
        administrative,
        presets,
        canViewRoles,
        onissued,
        send = undefined,
    }: {
        grantable: string[];
        administrative: string[];
        presets: Preset[];
        canViewRoles: boolean;
        onissued: (token: TokenRow, plainText: string) => void;
        send?: typeof fetch;
    } = $props();

    let name = $state('');
    let selected = $state<string[]>([]);
    let expires = $state<Expiry>(DEFAULT_EXPIRY);
    let busy = $state(false);
    let errors = $state<Record<string, string>>({});
    let problem = $state<string | null>(null);
    let needsConfirm = $state(false);

    const preset = $derived(presetOf(selected, presets));

    // The permissions shown before "Alle Rechte zeigen" (Tokens.dc.html):
    // the common ones of a script, and whatever is ticked. The rest, the
    // administrative ones among them, follow on request, so the form stays
    // short to work through with a keyboard.
    let showAll = $state(false);
    const shown = $derived(
        showAll
            ? grantable
            : grantable.filter((p) => COMMON_PERMISSIONS.includes(p) || selected.includes(p)),
    );
    const hidden = $derived(grantable.length - shown.length);

    function revealAll(event: MouseEvent): void {
        event.preventDefault();
        showAll = true;
    }

    function choosePreset(key: string): void {
        const found = presets.find((p) => p.key === key);
        if (found) {
            selected = inCatalogueOrder(found.permissions, grantable);
        }
    }

    function toggle(permission: string, on: boolean): void {
        selected = on
            ? inCatalogueOrder([...selected, permission], grantable)
            : selected.filter((p) => p !== permission);
    }

    function explain(result: Exclude<IssueResult, { ok: true }>): void {
        errors = result.reason === 'validation' ? result.errors : {};
        needsConfirm = result.reason === 'confirm_password';
        problem = {
            validation: null,
            privilege_escalation: t('tokens.refused', {
                permissions:
                    result.reason === 'privilege_escalation'
                        ? result.refused.map((p) => t(`perm.${p}`)).join(', ')
                        : '',
            }),
            confirm_password: t('tokens.confirm_password'),
            signed_out: t('tokens.signed_out'),
            failed: t('tokens.failed'),
        }[result.reason];
    }

    async function submit(event: SubmitEvent): Promise<void> {
        event.preventDefault();
        if (busy) {
            return;
        }
        busy = true;
        problem = null;
        needsConfirm = false;
        errors = {};

        const result = await issueToken({ name, permissions: selected, expires }, send);
        busy = false;

        if (result.ok) {
            onissued(result.token, result.plainText);
            name = '';
            selected = [];
            expires = DEFAULT_EXPIRY;
        } else {
            explain(result);
        }
    }
</script>

<form class="tr-panel tr-token-form" aria-labelledby="h-new-token" onsubmit={submit} novalidate>
    <h2 id="h-new-token" class="tr-panel-title">{t('tokens.new')}</h2>

    <Field
        purpose="name"
        label={t('tokens.name')}
        bind:value={name}
        name="name"
        required
        error={errors.name ?? null}
        hint={t('tokens.name_hint')}
    />

    <fieldset class="tr-choice">
        <legend class="tr-field-label">{t('tokens.preset')}</legend>
        <div role="radiogroup" class="tr-token-presets">
            {#each presets as option (option.key)}
                <label class="tr-choice-option">
                    <input
                        type="radio"
                        name="preset"
                        value={option.key}
                        checked={preset === option.key}
                        disabled={!option.available}
                        onchange={() => choosePreset(option.key)}
                    />
                    {t(`tokens.preset.${option.key}`)}
                </label>
            {/each}
            <label class="tr-choice-option">
                <input
                    type="radio"
                    name="preset"
                    value={CUSTOM}
                    checked={preset === CUSTOM}
                    onchange={() => (selected = [])}
                />
                {t('tokens.preset.custom')}
            </label>
        </div>
    </fieldset>

    <fieldset
        id="tk-permissions"
        class="tr-choice"
        aria-describedby={errors.permissions ? 'tk-perm-error' : undefined}
    >
        <legend class="tr-field-label">{t('tokens.permissions')}</legend>
        {#each shown as permission (permission)}
            <label class="tr-choice-option tr-token-permission">
                <input
                    type="checkbox"
                    value={permission}
                    checked={selected.includes(permission)}
                    onchange={(e) => toggle(permission, e.currentTarget.checked)}
                />
                <span>
                    <span>
                        {t(`perm.${permission}`)}
                        {#if administrative.includes(permission)}
                            <span class="tr-sub">· {t('tokens.administrative')}</span>
                        {/if}
                    </span>
                    <code class="tr-code">{permission}</code>
                </span>
            </label>
        {/each}
        {#if errors.permissions}
            <p id="tk-perm-error" class="tr-field-error">{errors.permissions}</p>
        {/if}
        {#if hidden > 0}
            <a
                href="#tk-permissions"
                class="tr-row-link"
                aria-controls="tk-permissions"
                aria-expanded="false"
                onclick={revealAll}
            >
                {t('tokens.all_permissions')}
            </a>
        {:else if canViewRoles}
            <a href="/roles" class="tr-row-link">{t('tokens.what_permissions_mean')}</a>
        {/if}
    </fieldset>

    <div class="tr-field">
        <label class="tr-field-label" for="tk-expires">{t('tokens.expires')}</label>
        <select id="tk-expires" class="tr-select" bind:value={expires}>
            {#each EXPIRY_CHOICES as choice (choice)}
                <option value={choice}>{t(`tokens.expires.${choice}`)}</option>
            {/each}
        </select>
        {#if errors.expires}
            <p class="tr-field-error">{errors.expires}</p>
        {/if}
    </div>

    <p class="tr-sub">{t('tokens.ceiling')}</p>

    {#if problem}
        <p class="tr-error" role="alert">
            {problem}
            {#if needsConfirm}
                <a href="/tokens" class="tr-row-link">{t('tokens.confirm_again')}</a>
            {/if}
        </p>
    {/if}

    <div>
        <button type="submit" class="tr-button tr-button--primary" disabled={busy} aria-busy={busy}>
            {t('tokens.create')}
        </button>
    </div>
</form>
