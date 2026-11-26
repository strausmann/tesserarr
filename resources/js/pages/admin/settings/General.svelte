<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    export const layout = { title: 'general.title' };
</script>

<script lang="ts">
    import Check from '@lucide/svelte/icons/check';
    import Copy from '@lucide/svelte/icons/copy';
    import Lock from '@lucide/svelte/icons/lock';
    import { page, router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Field from '@/components/ui/Field.svelte';
    import Segmented from '@/components/ui/Segmented.svelte';
    import { t } from '@/lib/i18n';

    // General settings (Settings-General.dc.html, Settings-General-Phone.dc.html):
    // what the operator keeps in the interface, and below it what stays in the
    // environment, read-only. A field an environment variable overrides is
    // locked and says which variable. The sections of later versions on the
    // artboard (version warnings, transfer history, devices per user) come
    // with their tasks.
    type Setting = {
        value: string | number;
        source: 'environment' | 'stored' | 'default';
        env: string;
        env_invalid: boolean;
        env_refuses_migrate: boolean;
        default: string | number;
    };
    type Settings = Record<string, Setting>;

    let {
        settings,
        options,
        environment,
        secrets,
        request,
    }: {
        settings: Settings;
        options: {
            time_zones: string[];
            locales: string[];
            page_sizes: number[];
            themes: string[];
        };
        environment: { env: string; value: string }[];
        secrets: { env: string; set: boolean }[];
        request: { client: string; proxy: string | null };
    } = $props();

    const errors = $derived(
        ((page.props as { errors?: Record<string, string> }).errors ?? {}) as Record<string, string>,
    );

    const fields = [
        'instance_display_name',
        'locale_default',
        'time_zone',
        'ui_page_size',
        'ui_theme_default',
        'invitations_default_days',
        'audit_retention_days',
        'backups_keep',
    ] as const;
    type FieldName = (typeof fields)[number];

    const fromServer = (): Record<FieldName, string> =>
        Object.fromEntries(fields.map((f) => [f, String(settings[f].value)])) as Record<FieldName, string>;

    // What is typed, until it is saved or discarded.
    let draft = $state<Record<FieldName, string>>(fromServer());
    let saved = $state(false);
    // A visit hands over new props objects also when the server refused the
    // save; the draft is replaced only when the stored values themselves
    // changed, so a refused save keeps what was typed.
    let seen = JSON.stringify(fromServer());
    $effect(() => {
        const now = JSON.stringify(fromServer());
        if (now !== seen) {
            seen = now;
            draft = fromServer();
        }
    });

    const locked = (f: FieldName): boolean => settings[f].source === 'environment';
    const dirty = $derived(fields.some((f) => !locked(f) && draft[f] !== String(settings[f].value)));

    function digits(f: FieldName): (event: Event) => void {
        return (event) => {
            const input = event.currentTarget as HTMLInputElement;
            draft[f] = input.value.replace(/[^0-9]/g, '').slice(0, 4);
            input.value = draft[f];
            saved = false;
        };
    }

    function save(): void {
        const body = Object.fromEntries(fields.filter((f) => !locked(f)).map((f) => [f, draft[f]]));
        router.put('/settings/general', body, {
            preserveScroll: true,
            onSuccess: () => (saved = true),
        });
    }

    function discard(): void {
        draft = fromServer();
        saved = false;
    }

    // A copied value shows a check for a moment (the artboard's copy button).
    let copied = $state('');
    async function copy(id: string, text: string): Promise<void> {
        try {
            await navigator.clipboard.writeText(text);
            copied = id;
            setTimeout(() => (copied = copied === id ? '' : copied), 1600);
        } catch {
            copied = '';
        }
    }

    const label = (key: string): string => t(`general.label.${key}`);
    const locales = $derived(options.locales.map((l) => ({ value: l, label: t(`general.locale.${l}`) })));
    const themes = $derived(options.themes.map((v) => ({ value: v, label: t(`general.theme.${v}`) })));
    const sizes = $derived(options.page_sizes.map((n) => ({ value: String(n), label: String(n) })));
    const docs = 'https://strausmann.github.io/tesserarr/install/configuration/';
</script>

<AppHead title={t('general.title')} />

{#snippet lock(f: FieldName)}
    {#if locked(f)}
        <p class="tr-settings-locked" id="lock-{f}">
            <Lock class="size-4 shrink-0" aria-hidden="true" />
            <span>{t('general.overridden', { env: settings[f].env })}</span>
        </p>
    {:else if settings[f].env_invalid}
        <p class="tr-settings-locked" id="lock-{f}">
            <span>
                {t(
                    settings[f].env_refuses_migrate ? 'general.env_invalid_backups' : 'general.env_invalid',
                    { env: settings[f].env },
                )}
            </span>
        </p>
    {/if}
{/snippet}

<form
    class="tr-page tr-settings"
    onsubmit={(event) => {
        event.preventDefault();
        save();
    }}
>
    <header class="tr-page-header">
        <div>
            <h1 class="tr-page-title">{t('general.title')}</h1>
            <p class="tr-lead">{t('general.lead')}</p>
        </div>
    </header>

    <div class="tr-settings-grid tr-settings-grid--two">
        <section aria-labelledby="h-instance" class="tr-panel">
            <h2 id="h-instance" class="tr-panel-title">{t('general.instance')}</h2>
            <Field
                purpose="name"
                label={label('display_name')}
                bind:value={draft.instance_display_name}
                hint={t('general.hint.display_name')}
                error={errors.instance_display_name ?? null}
                disabled={locked('instance_display_name')}
                oninput={() => (saved = false)}
            />
            {@render lock('instance_display_name')}
        </section>

        <section aria-labelledby="h-region" class="tr-panel">
            <h2 id="h-region" class="tr-panel-title">{t('general.region')}</h2>
            <Segmented
                id="locale"
                label={label('locale')}
                options={locales}
                bind:value={draft.locale_default}
                disabled={locked('locale_default')}
                hint={t('general.hint.locale')}
            />
            {@render lock('locale_default')}
            {#if errors.locale_default}<p class="tr-field-error">{errors.locale_default}</p>{/if}

            <div class="tr-field">
                <label for="time-zone" class="tr-field-label">{label('time_zone')}</label>
                <div class="tr-field-box">
                    <input
                        id="time-zone"
                        class="tr-field-input"
                        list="time-zones"
                        type="text"
                        autocomplete="off"
                        autocapitalize="none"
                        autocorrect="off"
                        spellcheck="false"
                        enterkeyhint="done"
                        placeholder={t('general.time_zone.search')}
                        aria-describedby="time-zone-hint"
                        aria-invalid={errors.time_zone ? 'true' : undefined}
                        disabled={locked('time_zone')}
                        bind:value={draft.time_zone}
                        oninput={() => (saved = false)}
                    />
                    <datalist id="time-zones">
                        {#each options.time_zones as zone (zone)}
                            <option value={zone}></option>
                        {/each}
                    </datalist>
                </div>
                <p id="time-zone-hint" class="tr-field-hint">{t('general.hint.time_zone')}</p>
                {#if errors.time_zone}<p class="tr-field-error">{errors.time_zone}</p>{/if}
            </div>
            {@render lock('time_zone')}
        </section>
    </div>

    <section aria-labelledby="h-defaults" class="tr-panel">
        <h2 id="h-defaults" class="tr-panel-title">{t('general.defaults')}</h2>
        <div class="tr-settings-grid tr-settings-grid--two">
            <div class="flex flex-col gap-2">
                <Segmented
                    id="page-size"
                    label={label('page_size')}
                    options={sizes}
                    bind:value={draft.ui_page_size}
                    disabled={locked('ui_page_size')}
                    hint={t('general.hint.page_size')}
                />
                {@render lock('ui_page_size')}
                {#if errors.ui_page_size}<p class="tr-field-error">{errors.ui_page_size}</p>{/if}
            </div>
            <div class="flex flex-col gap-2">
                <Segmented
                    id="theme"
                    label={label('theme')}
                    options={themes}
                    bind:value={draft.ui_theme_default}
                    disabled={locked('ui_theme_default')}
                    hint={t('general.hint.theme')}
                />
                {@render lock('ui_theme_default')}
                {#if errors.ui_theme_default}<p class="tr-field-error">{errors.ui_theme_default}</p>{/if}
            </div>
            <div class="flex flex-col gap-2">
                <Field
                    purpose="number"
                    label={label('invitation_days')}
                    bind:value={draft.invitations_default_days}
                    hint={t('general.hint.invitation_days')}
                    error={errors.invitations_default_days ?? null}
                    disabled={locked('invitations_default_days')}
                    oninput={digits('invitations_default_days')}
                />
                {@render lock('invitations_default_days')}
            </div>
            <div class="flex flex-col gap-2">
                <Field
                    purpose="number"
                    label={label('audit_days')}
                    bind:value={draft.audit_retention_days}
                    hint={t('general.hint.audit_days')}
                    error={errors.audit_retention_days ?? null}
                    disabled={locked('audit_retention_days')}
                    oninput={digits('audit_retention_days')}
                />
                {@render lock('audit_retention_days')}
            </div>
            <div class="flex flex-col gap-2">
                <Field
                    purpose="number"
                    label={label('backups_keep')}
                    bind:value={draft.backups_keep}
                    hint={t('general.hint.backups_keep')}
                    error={errors.backups_keep ?? null}
                    disabled={locked('backups_keep')}
                    oninput={digits('backups_keep')}
                />
                {@render lock('backups_keep')}
            </div>
        </div>
    </section>

    <section aria-labelledby="h-env" class="tr-panel">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="h-env" class="tr-panel-title">{t('general.env.title')}</h2>
                <p class="tr-field-hint">{t('general.env.lead')}</p>
            </div>
            <a class="tr-about-link" href={docs} rel="noopener noreferrer" target="_blank">install/configuration.md</a>
        </div>

        <div>
            {#each environment as row (row.env)}
                <div class="tr-env-row">
                    <span class="font-bold">{t(`general.env.row.${row.env}`)} <code class="tr-code">{row.env}</code></span>
                    {#if row.env !== 'TESSERARR_ROLE'}
                        <button
                            type="button"
                            class="tr-icon-button"
                            aria-label={t('general.copy', { name: t(`general.env.row.${row.env}`) })}
                            onclick={() => copy(row.env, row.value)}
                        >
                            {#if copied === row.env}<Check class="size-[18px]" aria-hidden="true" />{:else}<Copy class="size-[18px]" aria-hidden="true" />{/if}
                        </button>
                    {/if}
                    <code class="tr-code">{row.value === '' ? t('general.env.none') : row.value}</code>
                </div>
            {/each}
            {#each secrets as row (row.env)}
                <div class="tr-env-row">
                    <span class="font-bold">{t(`general.env.row.${row.env}`)} <code class="tr-code">{row.env}</code></span>
                    <span class="tr-tag" class:tr-tag--bad={!row.set}>{row.set ? t('general.env.set') : t('general.env.not_set')}</span>
                </div>
            {/each}
        </div>

        <h3 class="font-bold">{t('general.request.title')}</h3>
        <div>
            <div class="tr-env-row">
                <span class="font-bold">{t('general.request.client')}</span>
                <button
                    type="button"
                    class="tr-icon-button"
                    aria-label={t('general.copy', { name: t('general.request.client') })}
                    onclick={() => copy('client', request.client)}
                >
                    {#if copied === 'client'}<Check class="size-[18px]" aria-hidden="true" />{:else}<Copy class="size-[18px]" aria-hidden="true" />{/if}
                </button>
                <code class="tr-code">{request.client}</code>
            </div>
            {#if request.proxy}
                <div class="tr-env-row">
                    <span class="font-bold">{t('general.request.proxy')}</span>
                    <code class="tr-code">{request.proxy}</code>
                </div>
            {/if}
        </div>
    </section>

    <div class="tr-savebar">
        <div class="tr-actions">
            <button type="submit" class="tr-button tr-button--primary" disabled={!dirty}>{t('general.save')}</button>
            <button type="button" class="tr-button" disabled={!dirty} onclick={discard}>{t('general.discard')}</button>
        </div>
        <p class="tr-savebar-note" role="status" aria-live="polite">
            {saved && !dirty ? t('general.saved') : t('general.logged')}
        </p>
    </div>
</form>
