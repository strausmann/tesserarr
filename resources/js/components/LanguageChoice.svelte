<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { t } from '@/lib/i18n';

    /**
     * The language of the interface, stored in the profile (every device).
     * "As the browser says" clears it: then Accept-Language decides
     * (App\Http\Middleware\SetLocale).
     */
    const stored = $derived(
        ((page.props.auth?.user as { locale?: string | null } | null)?.locale ??
            '') as string,
    );
    const options = [
        { value: '', label: 'settings.language.browser' },
        { value: 'de', label: 'settings.language.de', lang: 'de' },
        { value: 'en', label: 'settings.language.en', lang: 'en' },
    ];

    function choose(value: string): void {
        router.patch(
            '/settings/language',
            { locale: value === '' ? null : value },
            { preserveScroll: true },
        );
    }
</script>

<fieldset class="tr-choice">
    <legend class="tr-panel-title">{t('settings.language.title')}</legend>
    <p class="text-small text-ink-2">{t('settings.language.description')}</p>
    {#each options as option (option.value)}
        <label class="tr-choice-option">
            <input
                type="radio"
                name="locale"
                value={option.value}
                checked={stored === option.value}
                onchange={() => choose(option.value)}
            />
            <span lang={option.lang}>{t(option.label)}</span>
        </label>
    {/each}
</fieldset>
