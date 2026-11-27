<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import { t } from '@/lib/i18n';

    /**
     * The security section of "My account" (pre-flight K21). Task 8a shows
     * what is set up and leads to the page where passkeys, the authenticator
     * app and the password are managed; tasks 11 and 12 move that page's
     * controls in here, 10a adds the PGP key, 16 the sign-in methods.
     */
    let { security }: { security: { passkeys: number; two_factor: boolean } } = $props();
</script>

<section aria-labelledby="h-security" class="tr-panel">
    <h2 id="h-security" class="tr-panel-title">{t('account.security.title')}</h2>
    <dl class="tr-email-state">
        <div>
            <dt class="tr-sub">{t('account.security.passkeys')}</dt>
            <dd>{t('account.security.passkey_count', { count: security.passkeys })}</dd>
        </div>
        <div>
            <dt class="tr-sub">{t('account.security.two_factor')}</dt>
            <dd>{t(security.two_factor ? 'account.security.on' : 'account.security.off')}</dd>
        </div>
    </dl>
    <Link href="/settings/security" class="tr-button self-start">{t('account.security.manage')}</Link>
</section>
