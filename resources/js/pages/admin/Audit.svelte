<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    export const layout = { title: 'audit.title' };
</script>

<script lang="ts">
    import { Link, page, router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import EmptyState from '@/components/EmptyState.svelte';
    import Field from '@/components/ui/Field.svelte';
    import { t } from '@/lib/i18n';

    // The audit log (Audit.dc.html): newest first, a page as long as the setting ui.page_size says, with
    // filters for the period, the person, the action and the origin. The log
    // names people by their account, never by an address, and holds no
    // secrets.
    type Entry = {
        id: number;
        at: string;
        who: string;
        source: 'ui' | 'cli' | 'api';
        action: string;
        subject: string | null;
        outcome: 'done' | 'denied';
    };
    type Filters = { period: string; source: string; who: string | null; q: string | null };

    let {
        entries,
        total,
        newer,
        older,
        filters,
        people,
    }: {
        entries: Entry[];
        total: number;
        newer: string | null;
        older: string | null;
        filters: Filters;
        people: { value: string; label: string }[];
    } = $props();

    let q = $state('');
    $effect(() => {
        q = filters.q ?? '';
    });

    function apply(change: Partial<Filters>): void {
        const next = { ...filters, ...change };
        const query: Record<string, string> = { period: next.period, source: next.source };
        if (next.who) {
            query.who = next.who;
        }
        if (next.q) {
            query.q = next.q;
        }
        router.get('/audit', query, { preserveState: true, preserveScroll: true, replace: true });
    }

    // Times are shown in the time zone of the instance (general setting
    // time.zone); a name the browser does not know falls back to its own zone.
    const time = $derived.by(() => {
        const timeZone = (page.props as { timeZone?: string }).timeZone;
        try {
            return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone });
        } catch {
            return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
        }
    });
    const sources = ['all', 'ui', 'cli', 'api'] as const;
</script>

<AppHead title={t('audit.title')} />

<div class="tr-page">
    <header class="tr-page-header">
        <div>
            <h1 class="tr-page-title">{t('audit.title')}</h1>
            <p class="tr-lead">{t('audit.lead')}</p>
        </div>
    </header>

    <form
        class="tr-filters"
        aria-label={t('audit.filters')}
        onsubmit={(e) => {
            e.preventDefault();
            apply({ q });
        }}
    >
        <div>
            <label for="audit-period">{t('audit.period')}</label>
            <select
                id="audit-period"
                class="tr-select"
                value={filters.period}
                onchange={(e) => apply({ period: e.currentTarget.value })}
            >
                {#each ['24h', '7d', '30d'] as period (period)}
                    <option value={period}>{t(`audit.period.${period}`)}</option>
                {/each}
            </select>
        </div>
        <div>
            <label for="audit-who">{t('audit.who')}</label>
            <select
                id="audit-who"
                class="tr-select"
                value={filters.who ?? ''}
                onchange={(e) => apply({ who: e.currentTarget.value || null })}
            >
                <option value="">{t('audit.who.all')}</option>
                <option value="cli">{t('audit.source.cli')}</option>
                {#each people as person (person.value)}
                    <option value={person.value}>{person.label}</option>
                {/each}
            </select>
        </div>
        <div>
            <Field
                purpose="search"
                label={t('audit.what')}
                placeholder={t('audit.what.placeholder')}
                bind:value={q}
                onkeydown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        apply({ q });
                    }
                }}
            />
        </div>
        <fieldset>
            <legend>{t('audit.source')}</legend>
            {#each sources as source (source)}
                <button
                    type="button"
                    class="tr-button"
                    aria-pressed={filters.source === source}
                    onclick={() => apply({ source })}
                >
                    {t(`audit.source.${source}`)}
                </button>
            {/each}
        </fieldset>
    </form>

    {#if entries.length === 0}
        <EmptyState title={t('dashboard.audit.empty.title')} body={t('audit.empty.body')} />
    {/if}

    <!-- The table scrolls sideways on a phone; keyboard users reach the scroll area (axe scrollable-region-focusable). -->
    <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
    <div class="tr-table-wrap" hidden={entries.length === 0} role="region" aria-label={t('audit.entries')} tabindex="0">
        <table class="tr-table" aria-label={t('audit.entries')}>
            <thead>
                <tr>
                    <th scope="col">{t('audit.col.time')}</th>
                    <th scope="col">{t('audit.col.who')}</th>
                    <th scope="col">{t('audit.col.action')}</th>
                    <th scope="col">{t('audit.col.outcome')}</th>
                </tr>
            </thead>
            <tbody>
                {#each entries as entry (entry.id)}
                    <tr>
                        <td class="tr-nowrap"><time datetime={entry.at}>{time.format(new Date(entry.at))}</time></td>
                        <td>
                            <span>{entry.who}</span>
                            <span class="tr-sub">{t(`audit.source.${entry.source}`)}</span>
                        </td>
                        <td>
                            <span>
                                {t(`audit.action.${entry.action}`)}{#if entry.subject}: {entry.subject}{/if}
                            </span>
                            <code class="tr-code tr-sub">{entry.action}</code>
                        </td>
                        <td>
                            <span class={entry.outcome === 'denied' ? 'tr-tag tr-tag--bad' : 'tr-tag'}>
                                {t(`audit.outcome.${entry.outcome}`)}
                            </span>
                        </td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>

    <nav class="tr-pager" aria-label={t('audit.pages')}>
        <span>{t('audit.count', { shown: entries.length, total })}</span>
        <span class="tr-actions">
            {#each [{ url: newer, key: 'audit.newer' }, { url: older, key: 'audit.older' }] as step (step.key)}
                {#if step.url}
                    <Link href={step.url} class="tr-button" preserveScroll>{t(step.key)}</Link>
                {:else}
                    <!-- Shown, but not followed: the first or the last page. -->
                    <a
                        href="/audit"
                        class="tr-button"
                        aria-disabled="true"
                        onclick={(e) => e.preventDefault()}>{t(step.key)}</a
                    >
                {/if}
            {/each}
        </span>
    </nav>
</div>
