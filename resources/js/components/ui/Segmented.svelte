<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    let {
        label,
        options,
        value = $bindable(''),
        disabled = false,
        hint = '',
        id,
    }: {
        label: string;
        options: { value: string; label: string }[];
        value?: string;
        disabled?: boolean;
        hint?: string;
        id: string;
    } = $props();

    /**
     * A choice of a few values as a row of buttons (a radio group): one tab
     * stop, the arrow keys move and choose. Each button is 44 px high.
     */
    function move(event: KeyboardEvent, index: number): void {
        const step =
            event.key === 'ArrowRight' || event.key === 'ArrowDown'
                ? 1
                : event.key === 'ArrowLeft' || event.key === 'ArrowUp'
                  ? -1
                  : 0;
        if (step === 0 || disabled) {
            return;
        }
        event.preventDefault();
        const next = (index + step + options.length) % options.length;
        value = options[next].value;
        document.getElementById(`${id}-${next}`)?.focus();
    }

    const selected = $derived(options.findIndex((o) => o.value === value));
</script>

<div class="tr-field">
    <span id="{id}-label" class="tr-field-label">{label}</span>
    <div class="tr-seg" role="radiogroup" aria-labelledby="{id}-label" aria-describedby={hint ? `${id}-hint` : undefined}>
        {#each options as option, index (option.value)}
            <button
                id="{id}-{index}"
                type="button"
                role="radio"
                class="tr-seg-option"
                aria-checked={value === option.value}
                tabindex={index === (selected < 0 ? 0 : selected) ? 0 : -1}
                {disabled}
                onclick={() => (value = option.value)}
                onkeydown={(event) => move(event, index)}
            >
                {option.label}
            </button>
        {/each}
    </div>
    {#if hint}
        <p id="{id}-hint" class="tr-field-hint">{hint}</p>
    {/if}
</div>
