<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    let counter = 0;
</script>

<script lang="ts">
    import X from '@lucide/svelte/icons/x';
    import type { Snippet } from 'svelte';
    import { tick } from 'svelte';
    import { t } from '@/lib/i18n';

    /**
     * The one dialog of the interface (CONTRIBUTING.md, "Web UI: the
     * on-screen keyboard and inputs"); every dialog and the phone menu use it.
     *
     * - The layer covers the visible area (`--vvh`, `--vv-top`), not the
     *   layout viewport, so with the keyboard open nothing hides under it.
     *   On a short screen the frame sits at the top; it is never taller than
     *   the visible area and scrolls inside, the footer with the main buttons
     *   stays in sight.
     * - Opening moves the focus in, Tab stays inside, Escape and a tap on the
     *   dimmed area close it, and the focus goes back to what opened it.
     * - A tap on the dimmed area closes without moving the focus first: the
     *   focus would otherwise land on the page, come back to the field and
     *   open the keyboard again (after Labelyard useBackdropClose.ts, #318).
     * - A child that handles Escape itself (an open list) calls
     *   preventDefault, and the dialog stays open: Escape works in two steps.
     */
    let {
        open = $bindable(false),
        title = '',
        label = '',
        variant = 'center',
        id = `tr-dialog-${++counter}`,
        showClose = true,
        initialFocus = '',
        returnFocus = null,
        onclose,
        children,
        footer,
    }: {
        open?: boolean;
        /** Visible heading; also the accessible name. */
        title?: string;
        /** Accessible name when there is no visible heading. */
        label?: string;
        variant?: 'center' | 'drawer';
        id?: string;
        showClose?: boolean;
        /** Selector of the element that gets the focus on opening (default: the first focusable after the close button). */
        initialFocus?: string;
        /** Where the focus goes on closing (default: what had it before). */
        returnFocus?: HTMLElement | null;
        onclose?: () => void;
        children?: Snippet;
        footer?: Snippet;
    } = $props();

    let frame = $state<HTMLElement | null>(null);
    let opener: HTMLElement | null = null;
    let pressed = false;

    const FOCUSABLE =
        'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function focusables(): HTMLElement[] {
        return frame
            ? [...frame.querySelectorAll<HTMLElement>(FOCUSABLE)].filter(
                  (el) => !el.closest('[hidden]'),
              )
            : [];
    }

    function close(): void {
        open = false;
    }

    $effect(() => {
        if (!open) {
            return;
        }
        opener =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        const root = document.documentElement;
        const overflow = root.style.overflow;
        root.style.overflow = 'hidden';
        void tick().then(() => {
            const chosen = initialFocus
                ? frame?.querySelector<HTMLElement>(initialFocus)
                : focusables().find(
                      (el) => !el.hasAttribute('data-dialog-close'),
                  );
            (chosen ?? focusables()[0] ?? frame)?.focus();
        });

        return () => {
            root.style.overflow = overflow;
            const back = returnFocus ?? opener;
            onclose?.();
            back?.focus();
        };
    });

    function keydown(event: KeyboardEvent): void {
        if (event.key === 'Escape') {
            if (!event.defaultPrevented) {
                event.preventDefault();
                close();
            }

            return;
        }
        if (event.key !== 'Tab') {
            return;
        }
        // The focus trap: Tab from the last element goes to the first, and
        // Shift+Tab from the first to the last.
        const all = focusables();
        if (all.length === 0) {
            event.preventDefault();
            frame?.focus();

            return;
        }
        const first = all[0];
        const last = all[all.length - 1];
        const active = document.activeElement;
        if (event.shiftKey && (active === first || active === frame)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    }

    // A press that starts and ends on the dimmed area itself closes.
    function down(event: PointerEvent): void {
        pressed =
            event.target === event.currentTarget &&
            event.isPrimary &&
            event.button === 0;
        if (pressed) {
            event.preventDefault();
        }
    }

    function up(event: PointerEvent): void {
        if (pressed && event.target === event.currentTarget) {
            close();
        }
        pressed = false;
    }
</script>

{#if open}
    <div
        class="tr-dialog-layer"
        data-variant={variant}
        role="presentation"
        onpointerdown={down}
        onpointerup={up}
        onpointercancel={() => (pressed = false)}
    >
        <div
            bind:this={frame}
            {id}
            class="tr-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby={title ? `${id}-title` : undefined}
            aria-label={title ? undefined : label}
            tabindex="-1"
            onkeydown={keydown}
        >
            {#if showClose}
                <button
                    type="button"
                    class="tr-icon-button tr-dialog-close"
                    data-dialog-close
                    aria-label={variant === 'drawer'
                        ? t('nav.close')
                        : t('dialog.close')}
                    onclick={close}
                >
                    <X class="size-5" aria-hidden="true" />
                </button>
            {/if}
            {#if title}
                <h2 id="{id}-title" class="tr-dialog-title">{title}</h2>
            {/if}
            <div class="tr-dialog-body">
                {@render children?.()}
            </div>
            {#if footer}
                <div class="tr-dialog-footer">{@render footer()}</div>
            {/if}
        </div>
    </div>
{/if}
