// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The part of the page the person can see right now.
 *
 * With the on-screen keyboard open, Chromium (with
 * `interactive-widget=resizes-content`, resources/views/app.blade.php)
 * shrinks the layout viewport itself, but Safari on iOS only shrinks the
 * *visual* viewport and leaves `100dvh`, fixed boxes and centred dialogs sized
 * for the whole screen. `window.visualViewport` is right in both cases, so
 * everything that must stay in sight is sized with `--vvh` and placed with
 * `--vv-top`, which `trackVisibleViewport` publishes (CONTRIBUTING.md, "Web UI:
 * the on-screen keyboard and inputs"). After Labelyard
 * frontend/src/shell/viewport.ts.
 */
export type VisibleViewport = {
    /** Height of the visible area in px. */
    height: number;
    /** Distance from the top of the layout viewport to the top of the visible area in px. */
    offsetTop: number;
};

type Win = Pick<
    Window,
    'innerHeight' | 'addEventListener' | 'removeEventListener'
> & { visualViewport?: VisualViewport | null };

/** The visible area; the layout viewport when the browser has no `visualViewport`. */
export function readViewport(win: Win = window): VisibleViewport {
    const vv = win.visualViewport;

    return vv
        ? { height: vv.height, offsetTop: vv.offsetTop }
        : { height: win.innerHeight, offsetTop: 0 };
}

/** Calls `listener` whenever the visible area changes (keyboard, rotation, zoom, browser bars). Returns the unsubscribe. */
export function subscribeViewport(
    listener: () => void,
    win: Win = window,
): () => void {
    const vv = win.visualViewport;
    win.addEventListener('resize', listener);
    vv?.addEventListener('resize', listener);
    vv?.addEventListener('scroll', listener);

    return () => {
        win.removeEventListener('resize', listener);
        vv?.removeEventListener('resize', listener);
        vv?.removeEventListener('scroll', listener);
    };
}

/**
 * Publishes the visible area as `--vvh` and `--vv-top` on `root` and keeps
 * them current. Returns the function that takes it all down again.
 *
 * It does not scroll the focused field into view: browsers do that when the
 * keyboard opens, and a second scroll from here moves pages nobody asked to
 * move.
 */
export function trackVisibleViewport(
    root: HTMLElement = document.documentElement,
    win: Win = window,
): () => void {
    const publish = () => {
        const { height, offsetTop } = readViewport(win);
        root.style.setProperty('--vvh', `${height}px`);
        root.style.setProperty('--vv-top', `${offsetTop}px`);
    };
    publish();
    const stop = subscribeViewport(publish, win);

    return () => {
        stop();
        root.style.removeProperty('--vvh');
        root.style.removeProperty('--vv-top');
    };
}

/** Where a list beside a field goes and how tall it may get. */
export type ListRoom = {
    placement: 'bottom' | 'top';
    maxHeight: number;
};

/**
 * Room for a list next to a field (a combobox, a popover), measured against
 * what is visible: below the field unless that leaves room for fewer than
 * four options and above has more. Measured again each time the list opens.
 */
export function listRoom(
    field: { top: number; bottom: number },
    view: VisibleViewport,
    opts: { gap: number; max: number; option: number },
): ListRoom {
    const bottom = view.offsetTop + view.height;
    const below = bottom - field.bottom - opts.gap;
    const above = field.top - view.offsetTop - opts.gap;
    const up = below < 4 * opts.option && above > below;

    return {
        placement: up ? 'top' : 'bottom',
        maxHeight: Math.max(
            2 * opts.option,
            Math.min(opts.max, Math.floor(up ? above : below)),
        ),
    };
}
