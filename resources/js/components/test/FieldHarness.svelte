<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts">
    // Test page for Field.test.ts: a form with two fields and a submit button.
    import Field from '@/components/ui/Field.svelte';
    import type { FieldPurpose } from '@/lib/fields';

    let {
        purpose = 'search',
        value = '',
        error = null,
        hint = '',
    }: { purpose?: FieldPurpose; value?: string; error?: string | null; hint?: string } = $props();

    // svelte-ignore state_referenced_locally
    let current = $state(value);
    let inputs = $state(0);
    let sent = $state(0);
</script>

<form
    onsubmit={(e) => {
        e.preventDefault();
        sent++;
    }}
>
    <Field
        {purpose}
        label="First"
        id="first"
        bind:value={current}
        {error}
        {hint}
        oninput={() => inputs++}
    />
    <Field purpose="name" label="Second" id="second" />
    <button type="submit">Send</button>
</form>
<p id="value">{current}</p>
<p id="inputs">{inputs}</p>
<p id="sent">{sent}</p>
