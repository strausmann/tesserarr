// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The fixed attributes of a field, by what the field is for. They decide the
 * keyboard a phone shows, what the browser fills in and whether it corrects
 * the text; they are set here and only here, never per call site
 * (components/ui/Field.svelte, CONTRIBUTING.md "Web UI: the on-screen
 * keyboard and inputs"). Labelyard left these to each call site and missed
 * some; that is the gap this table closes.
 *
 * `enterkeyhint` is not in the table: it depends on the field's place in its
 * form (`enterHint`).
 */
export type FieldPurpose =
    | 'search'
    | 'name'
    | 'username'
    | 'email'
    | 'password'
    | 'new-password'
    | 'one-time-code'
    | 'url'
    | 'api-key'
    | 'number';

export type FieldAttributes = {
    type: 'text' | 'search' | 'email' | 'password' | 'url';
    inputmode: 'text' | 'search' | 'email' | 'url' | 'numeric';
    autocomplete: string;
    autocapitalize: 'none' | 'sentences';
    autocorrect: 'on' | 'off';
    spellcheck: boolean;
    /** Shows the clear button (the cross) while the field has a value. Never on secrets. */
    clearable: boolean;
};

const verbatim = {
    autocapitalize: 'none',
    autocorrect: 'off',
    spellcheck: false,
} as const;

export const fieldPurposes: Record<FieldPurpose, FieldAttributes> = {
    search: {
        type: 'search',
        inputmode: 'search',
        autocomplete: 'off',
        ...verbatim,
        clearable: true,
    },
    // A name the person gives a thing (a share, a connection): typed prose.
    name: {
        type: 'text',
        inputmode: 'text',
        autocomplete: 'off',
        autocapitalize: 'sentences',
        autocorrect: 'on',
        spellcheck: true,
        clearable: true,
    },
    username: {
        type: 'text',
        inputmode: 'text',
        autocomplete: 'username',
        ...verbatim,
        clearable: true,
    },
    email: {
        type: 'email',
        inputmode: 'email',
        autocomplete: 'email',
        ...verbatim,
        clearable: true,
    },
    password: {
        type: 'password',
        inputmode: 'text',
        autocomplete: 'current-password',
        ...verbatim,
        clearable: false,
    },
    'new-password': {
        type: 'password',
        inputmode: 'text',
        autocomplete: 'new-password',
        ...verbatim,
        clearable: false,
    },
    'one-time-code': {
        type: 'text',
        inputmode: 'numeric',
        autocomplete: 'one-time-code',
        ...verbatim,
        clearable: false,
    },
    url: {
        type: 'url',
        inputmode: 'url',
        autocomplete: 'url',
        ...verbatim,
        clearable: true,
    },
    // The key of another service (Sonarr, Radarr): hidden like a password,
    // never offered to the password manager as the user's own.
    'api-key': {
        type: 'password',
        inputmode: 'text',
        autocomplete: 'off',
        ...verbatim,
        clearable: false,
    },
    number: {
        type: 'text',
        inputmode: 'numeric',
        autocomplete: 'off',
        ...verbatim,
        clearable: false,
    },
};

export type EnterHint = 'next' | 'done' | 'go' | 'search';

const TYPING =
    'input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):not([disabled]), textarea:not([disabled]), select:not([disabled])';

/** The typing fields of the form, in document order. */
export function formFields(form: HTMLFormElement): HTMLElement[] {
    return [...form.querySelectorAll<HTMLElement>(TYPING)];
}

/**
 * What the Enter key says on the phone keyboard: `search` on a search, `next`
 * inside a form, `go` on the last field of a form that can be sent, `done`
 * on the last field of one that cannot (or outside a form).
 */
export function enterHint(
    input: HTMLInputElement,
    purpose: FieldPurpose,
): EnterHint {
    if (purpose === 'search') {
        return 'search';
    }
    const form = input.form;
    if (!form) {
        return 'done';
    }
    const fields = formFields(form);
    if (fields.indexOf(input) < fields.length - 1) {
        return 'next';
    }

    return form.querySelector('[type=submit], button:not([type])')
        ? 'go'
        : 'done';
}

/**
 * Enter on a field that is not the last of its form moves to the next one
 * instead of sending the form. Returns whether it moved.
 */
export function enterMovesOn(input: HTMLInputElement): boolean {
    const form = input.form;
    if (!form) {
        return false;
    }
    const fields = formFields(form);
    const next = fields[fields.indexOf(input) + 1];
    if (!next) {
        return false;
    }
    next.focus();

    return true;
}

/**
 * After a form was sent and came back with errors: focus the first field
 * that is marked invalid and scroll it into the middle of the screen.
 * Returns the field, or null when none is invalid.
 */
export function focusFirstInvalid(root: ParentNode): HTMLElement | null {
    const field = root.querySelector<HTMLElement>('[aria-invalid="true"]');
    if (!field) {
        return null;
    }
    field.focus({ preventScroll: true });
    field.scrollIntoView?.({ block: 'center' });

    return field;
}
