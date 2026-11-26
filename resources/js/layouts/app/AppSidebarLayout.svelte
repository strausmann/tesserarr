<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import Menu from '@lucide/svelte/icons/menu';
    import type { Snippet } from 'svelte';
    import { onMount } from 'svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import AboutDialog from '@/components/AboutDialog.svelte';
    import AppNavigation from '@/components/AppNavigation.svelte';
    import UpdateNotice from '@/components/UpdateNotice.svelte';
    import Dialog from '@/components/ui/Dialog.svelte';
    import { Toaster } from '@/components/ui/sonner';
    import { t } from '@/lib/i18n';
    import { startVersionChecks } from '@/lib/updates.svelte';

    /**
     * The app frame (Dashboard.dc.html, Menu-Phone.dc.html). From 1024 px
     * (Tailwind `lg`) a fixed sidebar; below, a top bar with the menu button,
     * the logo and the page title, and the whole navigation in a drawer: a
     * modal Dialog with the focus kept inside, closed by Escape, by a tap
     * beside it or by following a link, after which the focus is back on the
     * menu button. There is no bar at the bottom.
     *
     * The update notice sits in the foot of the navigation and as a toast;
     * the version in the foot opens "About and licences". While the frame is
     * shown, the tab asks the server for its version now and then
     * (lib/updates.svelte.ts).
     */
    let {
        title = '',
        children,
    }: {
        /** Key of the page title in lang/*.json, shown in the top bar. */
        title?: string;
        children?: Snippet;
    } = $props();

    const DRAWER = 'tr-nav-drawer';
    let open = $state(false);
    let menuButton = $state<HTMLButtonElement | null>(null);

    let aboutOpen = $state(false);
    let aboutOpener = $state<HTMLElement | null>(null);

    /** From the drawer, the drawer closes first; the focus then returns to the menu button. */
    function openAbout(opener: HTMLElement): void {
        aboutOpener = open ? menuButton : opener;
        open = false;
        aboutOpen = true;
    }

    onMount(() => {
        const offNavigate = router.on('navigate', () => (open = false));
        const stopChecks = startVersionChecks();

        return () => {
            offNavigate();
            stopChecks();
        };
    });
</script>

<div class="tr-app">
    <a href="#main" class="tr-skip">{t('app.skip')}</a>

    <header class="tr-topbar">
        <button
            bind:this={menuButton}
            type="button"
            class="tr-icon-button"
            aria-label={t('nav.open')}
            aria-controls={DRAWER}
            aria-expanded={open}
            onclick={() => (open = true)}
        >
            <Menu class="size-[22px]" aria-hidden="true" />
        </button>
        <AppLogoIcon class="size-7 shrink-0" />
        <span class="tr-topbar-title">{title ? t(title) : 'Tesserarr'}</span>
    </header>

    {#snippet updateBox()}
        <UpdateNotice variant="box" />
    {/snippet}

    <aside class="tr-sidebar" aria-label={t('nav.drawer')}>
        <AppNavigation notice={updateBox} onabout={openAbout} />
    </aside>

    <Dialog
        bind:open
        id={DRAWER}
        variant="drawer"
        label={t('nav.drawer')}
        returnFocus={menuButton}
    >
        <AppNavigation notice={updateBox} onabout={openAbout} />
    </Dialog>

    <AboutDialog bind:open={aboutOpen} returnFocus={aboutOpener} />

    <main id="main" class="tr-main" tabindex="-1">
        {@render children?.()}
    </main>
    <UpdateNotice variant="toast" />
    <Toaster />
</div>
