<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import Dialog from '@/components/ui/Dialog.svelte';
    import { t } from '@/lib/i18n';

    /**
     * "About and licences" (the foot of Dashboard.dc.html): the running
     * version, the licence with the way to the source, the licences of the
     * fonts and, for the operator, whether a newer release has been
     * published. That last one is the optional prop `release`
     * (App\Updates\ReleaseCheck), asked with a partial reload when the dialog
     * opens, so no page pays for it. A tab built for another version gets an
     * Inertia 409 here instead; the reload is async, so the page stays and
     * the update notice says why. The release number came from GitHub and is
     * only ever shown as text; the link is built by the server.
     */
    type Release = { latest: string; newer: boolean; url: string } | null;

    let {
        open = $bindable(false),
        returnFocus = null,
    }: { open?: boolean; returnFocus?: HTMLElement | null } = $props();

    const SOURCE = 'https://github.com/strausmann/tesserarr';

    const shared = $derived(
        page.props as { version?: string; audience?: string; release?: Release },
    );
    const version = $derived(String(shared.version ?? ''));
    const operator = $derived(shared.audience === 'operator');
    let loading = $state(false);
    let wasOpen = false;

    // Each time the dialog opens: a visit in between drops the optional prop.
    $effect(() => {
        if (open && !wasOpen && operator) {
            loading = true;
            router.reload({
                only: ['release'],
                onFinish: () => (loading = false),
            });
        }
        wasOpen = open;
    });

    const release = $derived(shared.release ?? null);
</script>

<Dialog bind:open title={t('about.title')} {returnFocus}>
    <dl class="tr-about">
        <dt>{t('about.version')}</dt>
        <dd class="tr-about-version">{version}</dd>
        {#if operator}
            <dt>{t('about.release')}</dt>
            <dd data-testid="about-release">
                {#if loading && !release}
                    {t('about.release.checking')}
                {:else if release && release.newer}
                    {t('about.release.newer', { version: release.latest })}
                    <a
                        href={release.url}
                        class="tr-about-link"
                        target="_blank"
                        rel="noopener noreferrer">{t('about.release.notes')}</a
                    >
                {:else if release}
                    {t('about.release.current')}
                {:else}
                    {t('about.release.unknown')}
                {/if}
            </dd>
        {/if}
        <dt>{t('about.licence')}</dt>
        <dd>
            {t('about.licence.name')}
            <a
                href={SOURCE}
                class="tr-about-link"
                target="_blank"
                rel="noopener noreferrer">{t('about.source')}</a
            >
        </dd>
        <dt>{t('about.fonts')}</dt>
        <dd>
            {t('about.fonts.name')}
            <a href="/licenses/atkinson-hyperlegible-OFL.txt" class="tr-about-link"
                >{t('about.fonts.licence')}</a
            >
        </dd>
    </dl>
</Dialog>
