<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    export const layout = { title: 'roles.title' };
</script>

<script lang="ts">
    import Check from '@lucide/svelte/icons/check';
    import { page, router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Dialog from '@/components/ui/Dialog.svelte';
    import Field from '@/components/ui/Field.svelte';
    import { t } from '@/lib/i18n';

    // Roles and permissions (Roles.dc.html): one column per role. The
    // built-in roles show their fixed permissions; own roles have a box per
    // permission, and only the permissions the signed-in account holds can be
    // ticked (nobody grants what they lack). satellite.execute is never shown
    // as a box: only satellite tokens carry it.
    type Group = { key: string; permissions: string[] };
    type Role = {
        id: number;
        name: string;
        built_in: boolean;
        permissions: string[];
        members: number;
    };

    let {
        groups,
        roles,
        grantable,
    }: { groups: Group[]; roles: Role[]; grantable: string[] } = $props();

    const errors = $derived(
        ((page.props as { errors?: Record<string, string> }).errors ?? {}) as Record<string, string>,
    );

    // The boxes as ticked now, per own role; saved together.
    let draft = $state<Record<number, string[]>>({});
    $effect(() => {
        draft = Object.fromEntries(
            roles.filter((r) => !r.built_in).map((r) => [r.id, [...r.permissions]]),
        );
    });

    const own = $derived(roles.filter((r) => !r.built_in));
    const changed = $derived(
        own.filter(
            (r) => [...(draft[r.id] ?? [])].sort().join() !== [...r.permissions].sort().join(),
        ),
    );

    function roleName(role: Role): string {
        return role.built_in ? t(`roles.name.${role.name}`) : role.name;
    }

    function toggle(role: Role, permission: string, on: boolean): void {
        const current = draft[role.id] ?? [];
        draft[role.id] = on ? [...current, permission] : current.filter((p) => p !== permission);
    }

    /**
     * Saves the changed own roles one after the other: two visits at once
     * would cancel each other (Inertia keeps one), and only the last role
     * would be saved. What is saved is taken when Save is pressed; a refused
     * change stops the rest, and its message shows.
     */
    function save(): void {
        const queue = changed.map((r) => ({ id: r.id, permissions: [...(draft[r.id] ?? [])] }));
        const next = (): void => {
            const item = queue.shift();
            if (item) {
                router.put(
                    `/roles/${item.id}`,
                    { permissions: item.permissions },
                    { preserveScroll: true, preserveState: true, onSuccess: next },
                );
            }
        };
        next();
    }

    // The boxes are one tab stop (roving tabindex): Tab reaches the matrix
    // once, the arrow keys move inside it, Space ticks. Otherwise every box
    // would be a stop of its own, a few dozen of them on the way to "Save".
    const boxKey = (role: Role, permission: string) => `${role.id}:${permission}`;
    const boxes = $derived(
        groups.flatMap((g) =>
            g.permissions.flatMap((p) =>
                own.filter(() => grantable.includes(p)).map((r) => boxKey(r, p)),
            ),
        ),
    );
    let focused = $state<string | null>(null);
    const current = $derived(focused !== null && boxes.includes(focused) ? focused : (boxes[0] ?? null));

    function move(event: KeyboardEvent): void {
        const at = current === null ? -1 : boxes.indexOf(current);
        const step: Record<string, number> = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 };
        let next = at;
        if (event.key in step) {
            next = Math.min(boxes.length - 1, Math.max(0, at + step[event.key]));
        } else if (event.key === 'Home') {
            next = 0;
        } else if (event.key === 'End') {
            next = boxes.length - 1;
        } else {
            return;
        }
        event.preventDefault();
        focused = boxes[next];
        document.querySelector<HTMLInputElement>(`[data-box="${boxes[next]}"]`)?.focus();
    }

    let creating = $state(false);
    let newName = $state('');

    function create(): void {
        router.post(
            '/roles',
            { name: newName, permissions: [] },
            {
                preserveScroll: true,
                onSuccess: () => {
                    creating = false;
                    newName = '';
                },
            },
        );
    }

    let deleting = $state<Role | null>(null);

    function remove(): void {
        if (deleting) {
            router.delete(`/roles/${deleting.id}`, {
                preserveScroll: true,
                onFinish: () => (deleting = null),
            });
        }
    }
</script>

<AppHead title={t('roles.title')} />

<div class="tr-page">
    <header class="tr-page-header">
        <div>
            <h1 class="tr-page-title">{t('roles.title')}</h1>
            <p class="tr-lead">{t('roles.lead')}</p>
        </div>
        <button type="button" class="tr-button tr-button--primary" onclick={() => (creating = true)}>
            {t('roles.create')}
        </button>
    </header>

    <div class="tr-notes">
        <p class="tr-note" role="note">
            <strong>{t('roles.note.operator.title')}</strong>
            {t('roles.note.operator.body')}
        </p>
        <p class="tr-note" role="note">
            <strong>{t('roles.note.grant.title')}</strong>
            {t('roles.note.grant.body')}
        </p>
    </div>

    {#if errors.permissions || errors.role}
        <p class="tr-error" role="alert">{errors.permissions ?? errors.role}</p>
    {/if}

    <section aria-labelledby="h-matrix" class="flex flex-col gap-3">
        <h2 id="h-matrix" class="tr-panel-title">{t('roles.matrix')}</h2>
        <div class="tr-table-wrap">
            <table class="tr-table tr-matrix">
                <thead>
                    <tr>
                        <th scope="col">{t('roles.col.permission')}</th>
                        {#each roles as role (role.id)}
                            <th scope="col">
                                <span class="font-bold">{roleName(role)}</span>
                                <span class="tr-sub">
                                    {role.built_in ? t('roles.built_in') : t('roles.own')}
                                    · {t(role.members === 1 ? 'roles.members_one' : 'roles.members', { count: role.members })}
                                </span>
                            </th>
                        {/each}
                    </tr>
                </thead>
                <tbody>
                    {#each groups as group (group.key)}
                        <tr>
                            <th scope="colgroup" colspan={roles.length + 1}>{t(`roles.group.${group.key}`)}</th>
                        </tr>
                        {#each group.permissions as permission (permission)}
                            <tr>
                                <th scope="row">
                                    <span>{t(`perm.${permission}`)}</span>
                                    <code class="tr-code tr-sub">{permission}</code>
                                </th>
                                {#each roles as role (role.id)}
                                    <td>
                                        {#if role.built_in}
                                            {#if role.permissions.includes(permission)}
                                                <span class="tr-check" role="img" aria-label={t('roles.fixed_granted')}>
                                                    <Check size={16} aria-hidden="true" />
                                                </span>
                                            {:else}
                                                <span class="tr-check" role="img" aria-label={t('roles.fixed_missing')}>–</span>
                                            {/if}
                                        {:else}
                                            <label class="tr-check">
                                                <input
                                                    type="checkbox"
                                                    checked={(draft[role.id] ?? []).includes(permission)}
                                                    disabled={!grantable.includes(permission)}
                                                    aria-label={t('roles.box', {
                                                        permission: t(`perm.${permission}`),
                                                        role: role.name,
                                                    })}
                                                    data-box="{role.id}:{permission}"
                                                    tabindex={boxKey(role, permission) === current ? 0 : -1}
                                                    onfocus={() => (focused = boxKey(role, permission))}
                                                    onkeydown={move}
                                                    onchange={(e) => toggle(role, permission, e.currentTarget.checked)}
                                                />
                                            </label>
                                        {/if}
                                    </td>
                                {/each}
                            </tr>
                        {/each}
                    {/each}
                </tbody>
            </table>
        </div>
        <p class="tr-sub">{t('roles.satellite_hint')}</p>
    </section>

    <div class="tr-actions">
        <button
            type="button"
            class="tr-button tr-button--primary"
            aria-disabled={changed.length === 0}
            onclick={() => changed.length > 0 && save()}
        >
            {t('roles.save')}
        </button>
    </div>

    <section aria-labelledby="h-danger" class="tr-danger-zone">
        <div class="flex flex-1 flex-col gap-1">
            <h2 id="h-danger" class="tr-panel-title">{t('roles.danger.title')}</h2>
            <p class="tr-sub">{t('roles.danger.body')}</p>
        </div>
        <div class="tr-actions">
            {#each own as role (role.id)}
                <button type="button" class="tr-button tr-button--danger" onclick={() => (deleting = role)}>
                    {t('roles.delete', { role: role.name })}
                </button>
            {/each}
        </div>
    </section>
</div>

<Dialog bind:open={creating} title={t('roles.create')}>
    <form
        class="flex flex-col gap-3"
        onsubmit={(e) => {
            e.preventDefault();
            create();
        }}
    >
        <Field purpose="name" label={t('roles.name_label')} bind:value={newName} error={errors.name ?? null} required />
    </form>
    {#snippet footer()}
        <button type="button" class="tr-button" onclick={() => (creating = false)}>{t('common.cancel')}</button>
        <button type="button" class="tr-button tr-button--primary" onclick={create}>{t('roles.create')}</button>
    {/snippet}
</Dialog>

<Dialog
    open={deleting !== null}
    title={t('roles.delete_confirm.title', { role: deleting?.name ?? '' })}
    onclose={() => (deleting = null)}
>
    <p>{t('roles.delete_confirm.body', { count: deleting?.members ?? 0 })}</p>
    {#snippet footer()}
        <button type="button" class="tr-button" onclick={() => (deleting = null)}>{t('common.cancel')}</button>
        <button type="button" class="tr-button tr-button--danger" onclick={remove}>
            {t('roles.delete', { role: deleting?.name ?? '' })}
        </button>
    {/snippet}
</Dialog>
