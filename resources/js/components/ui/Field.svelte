<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script module lang="ts">
    let counter = 0;
</script>

<script lang="ts">
    import X from '@lucide/svelte/icons/x';
    import { onMount } from 'svelte';
    import type { HTMLInputAttributes } from 'svelte/elements';
    import type { EnterHint, FieldPurpose } from '@/lib/fields';
    import { enterHint, enterMovesOn, fieldPurposes } from '@/lib/fields';
    import { t } from '@/lib/i18n';

    /**
     * The one text field of the interface (CONTRIBUTING.md, "Web UI: the
     * on-screen keyboard and inputs"). What the field is for (`purpose`)
     * decides its type, keyboard, autofill and correction (lib/fields.ts);
     * call sites never set them. Enter moves to the next field of the form
     * and sends it from the last one; the Enter key of the phone keyboard
     * says which.
     *
     * The clear button shows only while the field has a value, never on a
     * secret, is 44 px square and keeps the focus in the field; it sends an
     * `input` event like typing would. An error shows under the field and is
     * tied to it with aria-describedby and aria-invalid.
     */
    let {
        purpose,
        label,
        value = $bindable(''),
        input = $bindable(null),
        id = `tr-field-${++counter}`,
        name = undefined,
        error = null,
        hint = '',
        hideLabel = false,
        required = false,
        disabled = false,
        placeholder = undefined,
        oninput = undefined,
        onkeydown = undefined,
    }: {
        purpose: FieldPurpose;
        label: string;
        value?: string;
        input?: HTMLInputElement | null;
        id?: string;
        name?: string;
        error?: string | null;
        /** A limit the field keeps (password rules, a format), shown under it. */
        hint?: string;
        hideLabel?: boolean;
        required?: boolean;
        disabled?: boolean;
        placeholder?: string;
        oninput?: HTMLInputAttributes['oninput'];
        onkeydown?: (event: KeyboardEvent) => void;
    } = $props();

    const attrs = $derived(fieldPurposes[purpose]);
    let enter = $state<EnterHint>('done');

    const describedBy = $derived(
        [hint ? `${id}-hint` : '', error ? `${id}-error` : '']
            .filter(Boolean)
            .join(' ') || undefined,
    );

    function place(): void {
        if (input) {
            enter = enterHint(input, purpose);
        }
    }

    onMount(place);

    function keydown(event: KeyboardEvent): void {
        onkeydown?.(event);
        if (
            event.defaultPrevented ||
            event.key !== 'Enter' ||
            event.isComposing ||
            purpose === 'search' ||
            !input
        ) {
            return;
        }
        if (enterMovesOn(input)) {
            event.preventDefault();
        }
    }

    function clear(): void {
        value = '';
        if (input) {
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
        }
    }
</script>

<div class="tr-field">
    <label for={id} class={hideLabel ? 'sr-only' : 'tr-field-label'}
        >{label}</label
    >
    <div class="tr-field-box">
        <input
            bind:this={input}
            bind:value
            {id}
            {name}
            {required}
            {disabled}
            {placeholder}
            type={attrs.type}
            inputmode={attrs.inputmode}
            autocomplete={attrs.autocomplete as HTMLInputAttributes['autocomplete']}
            autocapitalize={attrs.autocapitalize}
            autocorrect={attrs.autocorrect}
            spellcheck={attrs.spellcheck}
            enterkeyhint={enter}
            aria-invalid={error ? 'true' : undefined}
            aria-describedby={describedBy}
            class="tr-field-input"
            data-clearable={attrs.clearable && value !== '' ? 'true' : undefined}
            onfocus={place}
            onkeydown={keydown}
            {oninput}
        />
        {#if attrs.clearable && value !== '' && !disabled}
            <button
                type="button"
                class="tr-icon-button tr-field-clear"
                aria-label={t('field.clear')}
                onpointerdown={(event) => event.preventDefault()}
                onmousedown={(event) => event.preventDefault()}
                onclick={clear}
            >
                <X class="size-[18px]" aria-hidden="true" />
            </button>
        {/if}
    </div>
    {#if hint}
        <p id="{id}-hint" class="tr-field-hint">{hint}</p>
    {/if}
    {#if error}
        <p id="{id}-error" class="tr-field-error">{error}</p>
    {/if}
</div>
