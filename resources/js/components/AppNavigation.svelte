<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import { Link, page, router } from '@inertiajs/svelte';
    import AppLogo from '@/components/AppLogo.svelte';
    import { t } from '@/lib/i18n';
    import type { Audience } from '@/lib/navigation';
    import { homeFor, isCurrent, navigationFor } from '@/lib/navigation';
    import { logout } from '@/routes';

    /**
     * The content of the navigation, the same in the fixed sidebar (wide) and
     * in the drawer (narrow): the logo, the groups of the audience, and at the
     * foot the account, sign-out and the running version (Dashboard.dc.html).
     * The update notice of task 7a goes into `notice`.
     */
    let { notice }: { notice?: import('svelte').Snippet } = $props();

    const audience = $derived(
        ((page.props as { audience?: Audience }).audience ?? 'guest') as Audience,
    );
    const groups = $derived(navigationFor(audience));
    const user = $derived(page.props.auth?.user ?? null);
    const version = $derived(
        String((page.props as { version?: string }).version ?? ''),
    );
</script>

<Link href={homeFor(audience)} class="tr-nav-brand">
    <AppLogo />
</Link>

<nav aria-label={t('nav.main')} class="tr-nav">
    {#each groups as group (group.label ?? 'main')}
        {#if group.label}
            <p class="tr-nav-group">{t(group.label)}</p>
        {/if}
        {#each group.items as item (item.href + item.label)}
            <Link
                href={item.href}
                class="tr-nav-item"
                aria-current={isCurrent(item.href, page.url) ? 'page' : undefined}
            >
                {t(item.label)}
            </Link>
        {/each}
    {/each}
</nav>

{#if notice}
    <div class="tr-nav-notice">{@render notice()}</div>
{/if}

{#if user}
    <div class="tr-nav-foot">
        <Link href="/settings/profile" class="tr-nav-account">
            <span class="font-bold">{user.name}</span>
            <span class="text-ink-2"
                >{t(audience === 'friend' ? 'nav.role.friend' : 'nav.role.operator')} · {t(
                    'nav.account',
                )}</span
            >
        </Link>
        <Link
            href={logout()}
            as="button"
            class="tr-nav-signout"
            onclick={() => router.flushAll()}
        >
            {t('nav.sign_out')}
        </Link>
        <p class="tr-nav-version">{t('app.about', { version })}</p>
    </div>
{/if}
