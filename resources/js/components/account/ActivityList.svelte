<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    import { locale, t } from '@/lib/i18n';

    /**
     * Audit entries as App\Audit\AuditPresenter hands them over: names looked
     * up when shown, never an address. Used for the own activity and the
     * latest entries about one account.
     */
    export type ActivityEntry = {
        id: number;
        at: string;
        who: string;
        source: 'ui' | 'cli' | 'api';
        action: string;
        subject: string | null;
        outcome: 'done' | 'denied';
    };

    let { entries, label }: { entries: ActivityEntry[]; label: string } = $props();

    const when = (iso: string): string =>
        new Date(iso).toLocaleString(locale(), { dateStyle: 'medium', timeStyle: 'short' });
</script>

<ul class="tr-activity" aria-label={label}>
    {#each entries as entry (entry.id)}
        <li class="tr-activity-row">
            <span class="tr-activity-what">
                {t(`audit.action.${entry.action}`)}
                {#if entry.outcome === 'denied'}
                    <span class="tr-tag tr-tag--bad">{t('audit.outcome.denied')}</span>
                {/if}
            </span>
            <span class="tr-sub">
                {entry.who} · {t(`audit.source.${entry.source}`)}{#if entry.subject}
                    · {entry.subject}{/if}
            </span>
            <time class="tr-sub" datetime={entry.at}>{when(entry.at)}</time>
        </li>
    {/each}
</ul>
